<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Query;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphQL\Type\Definition\ObjectType;
use Magento\Framework\GraphQl\Schema\Type\TypeRegistry;

/**
 * Routes a prefilled field of an object type to the prefilled value: the
 * field's resolver reads the value under the prefiller key when the
 * prefiller put it there, and calls the core resolver otherwise. The
 * routing happens when the type registry hands a type out, so only the
 * types a query materializes are touched; a walk of the whole type map
 * builds every declared type on every php-fpm request.
 */
class RoutePrefilledFields
{
    private \WeakMap $routed;

    public function __construct(
        private readonly array $prefilledFields = [],
    ) {
        $this->routed = new \WeakMap();
    }

    public function afterGet(TypeRegistry $subject, $type, string $typeName)
    {
        if (!$type instanceof ObjectType || isset($this->routed[$type])) {
            return $type;
        }
        $this->routed[$type] = true;
        $names = $this->prefilledFields[$type->name] ?? [];
        foreach ($type->getInterfaces() as $interface) {
            $names = array_merge($names, $this->prefilledFields[$interface->name] ?? []);
        }
        foreach ($names as $name) {
            if (!$type->hasField($name)) {
                continue;
            }
            $field = $type->getField($name);
            $original = $field->resolveFn;
            $field->resolveFn = static fn($value, $args, $context, $info) =>
                isset($value[PrefillerInterface::KEY]) && array_key_exists($name, $value[PrefillerInterface::KEY])
                    ? $value[PrefillerInterface::KEY][$name]
                    : $original($value, $args, $context, $info);
        }

        return $type;
    }
}
