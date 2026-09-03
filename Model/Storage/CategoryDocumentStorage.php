<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage;

use GraphCommerce\CatalogStorefront\Model\Storage\Client\CommandInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\Config;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\DataDefinitionInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\QueryInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\Data\EntryIteratorInterface;

/**
 * One category document per store view, keyed by category id, as the
 * categories feed delivers it: name, path, direct children ids, activity.
 * The documents are read by id only, so the index maps nothing.
 */
class CategoryDocumentStorage
{
    private const ENTITY = 'category';

    private array $ensured = [];

    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly DataDefinitionInterface $dataDefinition,
        private readonly CommandInterface $command,
        private readonly QueryInterface $query,
    ) {
    }

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

    public function delete(string $storeViewCode, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $this->ensureIndex($storeViewCode);
        $this->command->bulkDelete($this->aliasName($storeViewCode), self::ENTITY, $ids);
    }

    /**
     * @return array[] documents keyed by category id, only those that exist
     */
    public function get(string $storeViewCode, array $ids, array $fields = ['*']): array
    {
        if (!$ids) {
            return [];
        }
        $this->ensureIndex($storeViewCode);
        $documents = [];
        foreach ($this->query->getEntries($this->aliasName($storeViewCode), self::ENTITY, array_values($ids), $fields) as $entry) {
            $documents[(int)$entry->getId()] = $entry->getData();
        }

        return $documents;
    }

    private function aliasName(string $storeViewCode): string
    {
        return $this->config->getAliasName() . '_category_' . $storeViewCode;
    }

    private function ensureIndex(string $storeViewCode): void
    {
        if (isset($this->ensured[$storeViewCode])) {
            return;
        }
        $alias = $this->aliasName($storeViewCode);
        if (!$this->dataDefinition->existsDataSource($alias)) {
            $dataSource = $this->state->getCurrentDataSourceName([self::ENTITY, $storeViewCode]);
            if (!$this->dataDefinition->existsDataSource($dataSource)) {
                $this->dataDefinition->createDataSource($dataSource, []);
                $this->dataDefinition->createEntity($dataSource, self::ENTITY, []);
            }
            $this->dataDefinition->createAlias($alias, $dataSource);
        }
        $this->ensured[$storeViewCode] = true;
    }
}
