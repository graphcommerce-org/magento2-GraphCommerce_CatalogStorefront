<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Query;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use Magento\Framework\GraphQl\Query\Fields;
use Magento\Framework\GraphQl\Schema\SchemaGeneratorInterface;

/**
 * Keeps one built schema per query shape per process.
 *
 * Magento prunes every type to the fields the current query names, so a
 * built schema belongs to the set of names in the query, not to the process.
 * The same shape asks for the same schema, and a persistent worker holds the
 * configuration it derives from for its lifetime; a configuration change
 * reaches a worker at its next restart. The type map is materialized at build
 * time, while this request's type registry is live, so a kept schema never
 * consults the registry after its between-request reset. Introspection asks
 * for the unpruned schema and is not kept.
 *
 * On a built schema the pre-filled fields (per type or interface name, see
 * di.xml) resolve to the value Prefill put on the parent and fall back to the
 * core resolver when the parent carries none.
 */
class ReuseSchema
{
    private const LIMIT = 200;

    /** @var array<string, Schema> */
    private array $schemas = [];

    /**
     * @param array<string, string[]> $prefilledFields field names per type or interface name
     */
    public function __construct(
        private readonly Fields $queryFields,
        private readonly array $prefilledFields = [],
    ) {
    }

    public function aroundGenerate(SchemaGeneratorInterface $subject, \Closure $proceed): Schema
    {
        $names = $this->queryFields->getFieldsUsedInQuery();
        if (!$names) {
            return $proceed();
        }
        ksort($names);
        $key = md5(implode(',', $names));
        if (!isset($this->schemas[$key])) {
            if (count($this->schemas) >= self::LIMIT) {
                $this->schemas = [];
            }
            $schema = $proceed();
            foreach ($schema->getTypeMap() as $type) {
                if ($type instanceof ObjectType) {
                    $this->routePrefilledFields($type);
                }
            }
            $this->schemas[$key] = $schema;
        }

        return $this->schemas[$key];
    }

    private function routePrefilledFields(ObjectType $type): void
    {
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
}
