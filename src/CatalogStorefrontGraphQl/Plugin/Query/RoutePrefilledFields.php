<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Query;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use Magento\Framework\GraphQl\Schema\SchemaGeneratorInterface;

/**
 * On a built schema the pre-filled fields (per type or interface name, see
 * di.xml `prefilledFields`) resolve to the value a prefiller put on the
 * parent, and fall back to the core resolver when the parent carries none.
 * A pre-filled field costs the executor a plain array read instead of a
 * resolver call with its ResolveInfo, argument validation and plugin chain.
 */
class RoutePrefilledFields
{
    /**
     * @param array<string, string[]> $prefilledFields field names per type or interface name
     */
    public function __construct(
        private readonly array $prefilledFields = [],
    ) {
    }

    public function afterGenerate(SchemaGeneratorInterface $subject, Schema $schema): Schema
    {
        foreach ($schema->getTypeMap() as $type) {
            if (!$type instanceof ObjectType) {
                continue;
            }
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
        }

        return $schema;
    }
}
