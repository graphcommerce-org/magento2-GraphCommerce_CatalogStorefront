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
 * mapping only the fields the entity declares.
 */
class MetadataDocumentStorage implements MetadataDocumentStorageInterface
{
    private const PAGE = 1000;

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

    /**
     * Pages through the index up to its result window (10000 by default).
     */
    public function all(string $entity, string $storeViewCode): array
    {
        $this->ensureIndex($entity, $storeViewCode);
        $documents = [];
        for ($from = 0; ; $from += self::PAGE) {
            $responses = $this->query->multiSearch(
                $this->aliasName($entity, $storeViewCode),
                [['from' => $from, 'size' => self::PAGE, 'query' => ['match_all' => new \stdClass()]]]
            );
            $hits = $responses[0]['hits']['hits'] ?? [];
            foreach ($hits as $hit) {
                $documents[$hit['_id']] = $hit['_source'];
            }
            if (count($hits) < self::PAGE) {
                return $documents;
            }
        }
    }

    public function find(string $entity, string $storeViewCode, array $filter, array $sort, int $from, int $size): array
    {
        $this->ensureIndex($entity, $storeViewCode);
        $responses = $this->query->multiSearch($this->aliasName($entity, $storeViewCode), [[
            'from' => $from,
            'size' => $size,
            'track_total_hits' => true,
            'query' => ['bool' => ['filter' => $this->terms($filter)]],
            'sort' => array_map(static fn(array $order) => [$order[0] => ['order' => $order[1]]], $sort),
        ]]);
        $documents = [];
        foreach ($responses[0]['hits']['hits'] ?? [] as $hit) {
            $documents[$hit['_id']] = $hit['_source'];
        }

        return ['documents' => $documents, 'total' => (int)($responses[0]['hits']['total']['value'] ?? 0)];
    }

    public function stats(string $entity, string $storeViewCode, string $groupField, array $groups, string $valueField): array
    {
        if (!$groups) {
            return [];
        }
        $this->ensureIndex($entity, $storeViewCode);
        $groups = array_values(array_map('strval', $groups));
        $responses = $this->query->multiSearch($this->aliasName($entity, $storeViewCode), [[
            'size' => 0,
            'query' => ['bool' => ['filter' => $this->terms([$groupField => $groups])]],
            'aggs' => ['groups' => [
                'terms' => ['field' => $groupField, 'size' => count($groups), 'include' => $groups],
                'aggs' => ['avg' => ['avg' => ['field' => $valueField]]],
            ]],
        ]]);
        $stats = [];
        foreach ($responses[0]['aggregations']['groups']['buckets'] ?? [] as $bucket) {
            $stats[(string)$bucket['key']] = ['count' => (int)$bucket['doc_count'], 'avg' => $bucket['avg']['value']];
        }

        return $stats;
    }

    /**
     * @return array[] a terms clause per filtered field
     */
    private function terms(array $filter): array
    {
        $clauses = [];
        foreach ($filter as $field => $values) {
            $clauses[] = ['terms' => [$field => array_values(array_map('strval', (array)$values))]];
        }

        return $clauses;
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
