<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client\CommandInterface;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client\Config;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client\DataDefinitionInterface;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client\QueryInterface;

/**
 * One index per entity and store view (`<alias>_<entity>_<store view>`),
 * mapping nothing: the documents are read by id or all at once.
 */
class MetadataDocumentStorage implements MetadataDocumentStorageInterface
{
    private const ALL_LIMIT = 1000;

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

    public function upsert(string $entity, string $storeViewCode, array $documents): void
    {
        if (!$documents) {
            return;
        }
        $this->ensureIndex($entity, $storeViewCode);
        $entries = [];
        foreach ($documents as $id => $document) {
            $entries[] = ['id' => $id] + $document;
        }
        $this->command->bulkUpdate($this->aliasName($entity, $storeViewCode), $entity, $entries);
    }

    public function delete(string $entity, string $storeViewCode, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $this->ensureIndex($entity, $storeViewCode);
        $this->command->bulkDelete($this->aliasName($entity, $storeViewCode), $entity, $ids);
    }

    public function get(string $entity, string $storeViewCode, array $ids, array $fields = []): array
    {
        if (!$ids) {
            return [];
        }
        $this->ensureIndex($entity, $storeViewCode);
        $documents = [];
        $entries = $this->query->getEntries($this->aliasName($entity, $storeViewCode), $entity, array_values($ids), $fields ?: ['*']);
        foreach ($entries as $entry) {
            $documents[$entry->getId()] = $entry->getData();
        }

        return $documents;
    }

    public function all(string $entity, string $storeViewCode): array
    {
        $this->ensureIndex($entity, $storeViewCode);
        $responses = $this->query->multiSearch(
            $this->aliasName($entity, $storeViewCode),
            [['size' => self::ALL_LIMIT, 'query' => ['match_all' => new \stdClass()]]]
        );
        $documents = [];
        foreach ($responses[0]['hits']['hits'] ?? [] as $hit) {
            $documents[$hit['_id']] = $hit['_source'];
        }

        return $documents;
    }

    private function aliasName(string $entity, string $storeViewCode): string
    {
        return $this->config->getAliasName() . '_' . $entity . '_' . $storeViewCode;
    }

    private function ensureIndex(string $entity, string $storeViewCode): void
    {
        if (isset($this->ensured[$entity . $storeViewCode])) {
            return;
        }
        $alias = $this->aliasName($entity, $storeViewCode);
        if (!$this->dataDefinition->existsDataSource($alias)) {
            $dataSource = $this->state->getCurrentDataSourceName([$entity, $storeViewCode]);
            if (!$this->dataDefinition->existsDataSource($dataSource)) {
                $this->dataDefinition->createDataSource($dataSource, []);
                $this->dataDefinition->createEntity($dataSource, $entity, []);
            }
            $this->dataDefinition->createAlias($alias, $dataSource);
        }
        $this->ensured[$entity . $storeViewCode] = true;
    }
}
