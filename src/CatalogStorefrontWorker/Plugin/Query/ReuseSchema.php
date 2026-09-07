<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Query;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use GraphQL\Type\Schema;
use Magento\Framework\GraphQl\Query\Fields;
use Magento\Framework\GraphQl\Schema\SchemaGeneratorInterface;

/**
 * Keeps one built schema per query shape per process.
 *
 * Magento prunes every type to the fields the current query names, so a
 * built schema belongs to the set of names in the query, not to the process.
 * The type map is materialized at build time, while this request's type
 * registry is live, so a kept schema never consults the registry after its
 * between-request reset. Introspection asks for the unpruned schema and is
 * not kept. The schemas live under the config generation: a cache flush or a
 * config cache clean drops them.
 */
/**
 * Keeps one built schema per query shape in a worker. Under php-fpm the
 * process serves one request, so the schema is built as core builds it,
 * without the type map materialized: that walk builds every declared type.
 */
class ReuseSchema
{
    private readonly Memo $schemas;

    public function __construct(
        private readonly Fields $queryFields,
        MemoFactory $memoFactory,
    ) {
        $this->schemas = $memoFactory->create(['name' => Generation::CONFIG, 'limit' => 200]);
    }

    public function aroundGenerate(SchemaGeneratorInterface $subject, \Closure $proceed): Schema
    {
        $names = $this->queryFields->getFieldsUsedInQuery();
        if (!$names || PHP_SAPI === 'fpm-fcgi') {
            return $proceed();
        }
        ksort($names);

        return $this->schemas->get(md5(implode(',', $names)), static function () use ($proceed): Schema {
            $schema = $proceed();
            $schema->getTypeMap();

            return $schema;
        });
    }
}
