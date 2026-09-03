<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\GraphQl;

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
 */
class ReuseSchema
{
    private const LIMIT = 200;

    /** @var array<string, Schema> */
    private array $schemas = [];

    public function __construct(
        private readonly Fields $queryFields,
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
            $schema->getTypeMap();
            $this->schemas[$key] = $schema;
        }

        return $this->schemas[$key];
    }
}
