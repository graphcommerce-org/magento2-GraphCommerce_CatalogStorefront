<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;

/**
 * One index per entity and store view, mapping only the fields the entity
 * declares.
 */
class MetadataDocumentStorage implements MetadataDocumentStorageInterface
{
    private const PAGE = 1000;

    public function __construct(
        private readonly Client $client,
        private readonly Index $index,
    ) {
    }

    public function upsert(string $entity, string $storeViewCode, array $documents): void
    {
        if ($documents) {
            $this->client->upsert($this->index->ensure($entity, $storeViewCode), $documents);
        }
    }

    public function delete(string $entity, string $storeViewCode, array $ids): void
    {
        if ($ids) {
            $this->client->delete($this->index->ensure($entity, $storeViewCode), $ids);
        }
    }

    public function drop(string $entity, string $storeViewCode): void
    {
        $this->index->drop($entity, $storeViewCode);
    }

    public function get(string $entity, string $storeViewCode, array $ids, array $fields = []): array
    {
        return $ids ? $this->client->get($this->client->indexName($entity, $storeViewCode), $ids, $fields) : [];
    }

    /**
     * Pages through the index up to its result window (10000 by default).
     */
    public function all(string $entity, string $storeViewCode): array
    {
        $documents = [];
        for ($from = 0; ; $from += self::PAGE) {
            $response = $this->client->search(
                $this->client->indexName($entity, $storeViewCode),
                ['from' => $from, 'size' => self::PAGE, 'query' => ['match_all' => new \stdClass()]]
            );
            $hits = $response['hits']['hits'] ?? [];
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
        $response = $this->client->search($this->client->indexName($entity, $storeViewCode), [
            'from' => $from,
            'size' => $size,
            'track_total_hits' => true,
            'query' => ['bool' => ['filter' => $this->terms($filter)]],
            'sort' => array_map(static fn(array $order) => [$order[0] => ['order' => $order[1]]], $sort),
        ]);
        $documents = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $documents[$hit['_id']] = $hit['_source'];
        }

        return ['documents' => $documents, 'total' => (int)($response['hits']['total']['value'] ?? 0)];
    }

    public function stats(string $entity, string $storeViewCode, string $groupField, array $groups, string $valueField): array
    {
        if (!$groups) {
            return [];
        }
        $groups = array_values(array_map('strval', $groups));
        $response = $this->client->search($this->client->indexName($entity, $storeViewCode), [
            'size' => 0,
            'query' => ['bool' => ['filter' => $this->terms([$groupField => $groups])]],
            'aggs' => ['groups' => [
                'terms' => ['field' => $groupField, 'size' => count($groups), 'include' => $groups],
                'aggs' => ['avg' => ['avg' => ['field' => $valueField]]],
            ]],
        ]);
        $stats = [];
        foreach ($response['aggregations']['groups']['buckets'] ?? [] as $bucket) {
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
}
