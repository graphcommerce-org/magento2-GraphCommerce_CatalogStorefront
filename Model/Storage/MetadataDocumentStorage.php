<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage;

use GraphCommerce\CatalogStorefront\Model\Storage\Client\CommandInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\Config;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\DataDefinitionInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\QueryInterface;

/**
 * Documents of one metadata feed per store view, keyed as the feed keys them:
 * categories by id, attributes by code, ratings by id. They are read by id or
 * all at once, so the index maps nothing. One instance per entity (di.xml).
 */
class MetadataDocumentStorage
{
    private const ALL_LIMIT = 1000;

    private array $ensured = [];

    public function __construct(
        private readonly Config $config,
        private readonly State $state,
        private readonly DataDefinitionInterface $dataDefinition,
        private readonly CommandInterface $command,
        private readonly QueryInterface $query,
        private readonly string $entity,
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
        $this->command->bulkUpdate($this->aliasName($storeViewCode), $this->entity, $entries);
    }

    public function delete(string $storeViewCode, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $this->ensureIndex($storeViewCode);
        $this->command->bulkDelete($this->aliasName($storeViewCode), $this->entity, $ids);
    }

    /**
     * @return array[] documents keyed by id, only those that exist
     */
    public function get(string $storeViewCode, array $ids, array $fields = ['*']): array
    {
        if (!$ids) {
            return [];
        }
        $this->ensureIndex($storeViewCode);
        $documents = [];
        foreach ($this->query->getEntries($this->aliasName($storeViewCode), $this->entity, array_values($ids), $fields) as $entry) {
            $documents[$entry->getId()] = $entry->getData();
        }

        return $documents;
    }

    /**
     * @return array[] every document of the store view keyed by id
     */
    public function all(string $storeViewCode): array
    {
        $this->ensureIndex($storeViewCode);
        $responses = $this->query->multiSearch(
            $this->aliasName($storeViewCode),
            [['size' => self::ALL_LIMIT, 'query' => ['match_all' => new \stdClass()]]]
        );
        $documents = [];
        foreach ($responses[0]['hits']['hits'] ?? [] as $hit) {
            $documents[$hit['_id']] = $hit['_source'];
        }

        return $documents;
    }

    private function aliasName(string $storeViewCode): string
    {
        return $this->config->getAliasName() . '_' . $this->entity . '_' . $storeViewCode;
    }

    private function ensureIndex(string $storeViewCode): void
    {
        if (isset($this->ensured[$storeViewCode])) {
            return;
        }
        $alias = $this->aliasName($storeViewCode);
        if (!$this->dataDefinition->existsDataSource($alias)) {
            $dataSource = $this->state->getCurrentDataSourceName([$this->entity, $storeViewCode]);
            if (!$this->dataDefinition->existsDataSource($dataSource)) {
                $this->dataDefinition->createDataSource($dataSource, []);
                $this->dataDefinition->createEntity($dataSource, $this->entity, []);
            }
            $this->dataDefinition->createAlias($alias, $dataSource);
        }
        $this->ensured[$storeViewCode] = true;
    }
}
