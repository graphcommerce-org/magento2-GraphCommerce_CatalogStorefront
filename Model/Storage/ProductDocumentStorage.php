<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage;

use GraphCommerce\CatalogStorefront\Model\Storage\Client\CommandInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\Config;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\DataDefinitionInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\QueryInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\Data\EntryIteratorInterface;

/**
 * Product documents per store view, one document per product, assembled from
 * feed slices: the products feed writes the base fields, the prices and stock
 * feeds patch their own key into the same document.
 */
class ProductDocumentStorage
{
    private const ENTITY = 'product';

    /** @var array<string, bool> */
    private array $ensured = [];

    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly DataDefinitionInterface $dataDefinition,
        private readonly CommandInterface $command,
        private readonly QueryInterface $query,
    ) {
    }

    public function aliasName(string $storeViewCode): string
    {
        return $this->config->getAliasName() . '_' . $storeViewCode;
    }

    /**
     * @param array<int, array> $documents entity_id => partial document
     */
    public function upsert(string $storeViewCode, array $documents): void
    {
        if (!$documents) {
            return;
        }
        $this->ensureIndex($storeViewCode);
        $entries = [];
        foreach ($documents as $id => $document) {
            $entries[] = ['id' => $id] + $document;
        }
        $this->command->bulkUpdate($this->aliasName($storeViewCode), self::ENTITY, $entries);
    }

    /**
     * @param int[] $ids
     */
    public function delete(string $storeViewCode, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $this->ensureIndex($storeViewCode);
        $this->command->bulkDelete($this->aliasName($storeViewCode), self::ENTITY, $ids);
    }

    /**
     * @param int[] $ids
     */
    public function get(string $storeViewCode, array $ids): EntryIteratorInterface
    {
        return $this->query->getEntries($this->aliasName($storeViewCode), self::ENTITY, $ids, ['*']);
    }

    private function ensureIndex(string $storeViewCode): void
    {
        if (isset($this->ensured[$storeViewCode])) {
            return;
        }
        $alias = $this->aliasName($storeViewCode);
        if (!$this->dataDefinition->existsDataSource($alias)) {
            $dataSource = $this->state->getCurrentDataSourceName([$storeViewCode]);
            if (!$this->dataDefinition->existsDataSource($dataSource)) {
                $this->dataDefinition->createDataSource($dataSource, []);
                $this->dataDefinition->createEntity($dataSource, self::ENTITY, []);
            }
            $this->dataDefinition->createAlias($alias, $dataSource);
        }
        $this->ensured[$storeViewCode] = true;
    }
}
