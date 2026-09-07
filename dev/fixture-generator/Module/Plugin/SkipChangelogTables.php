<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontFixtureGenerator\Plugin;

use Magento\Framework\App\ResourceConnection;
use Magento\Setup\Model\FixtureGenerator\EntityGenerator;
use Magento\Setup\Model\FixtureGenerator\EntityGeneratorFactory;

/**
 * The performance fixture generator replays the SQL of one template save for
 * every generated entity, and the exporter marks a saved product in its
 * changelog tables from PHP, so those inserts are in the replay. A changelog
 * table has no foreign key to the entity, which stops the generator; every
 * `_cl` table is mapped to write nothing. The exporter's own filter on the
 * SQL collector needs an interceptor the compile does not produce for the
 * setup classes.
 */
class SkipChangelogTables
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function beforeCreate(EntityGeneratorFactory $subject, array $data = []): array
    {
        $connection = $this->resourceConnection->getConnection();
        foreach ($connection->getTables($connection->getTableName('%_cl')) as $table) {
            $data['customTableMap'][$table] ??= [
                'entity_id_field' => EntityGenerator::SKIP_ENTITY_ID_BINDING,
                'handler' => static fn() => [],
            ];
        }

        return [$data];
    }
}
