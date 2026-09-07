<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\EntityMappings;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;

/**
 * One index per entity and store view, mapping only the fields the entity
 * declares.
 */
class MetadataDocumentStorage implements MetadataDocumentStorageInterface
{
    private const PAGE = 1000;
    private const WINDOW = 10000;

    public function __construct(
        private readonly Client $client,
        private readonly Index $index,
        private readonly EntityMappings $mappings,
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

    public function stage(string $entity, string $storeViewCode): void
    {
        $this->index->stage($entity, $storeViewCode);
    }

    public function promote(string $entity, string $storeViewCode): void
    {
        $this->index->promote($entity, $storeViewCode);
    }

    public function count(string $entity, string $storeViewCode): int
    {
        return $this->client->count($this->client->indexName($entity, $storeViewCode));
    }

    public function get(string $entity, string $storeViewCode, array $ids, array $fields = []): array
    {
        return $ids ? $this->batch($storeViewCode, [['entity' => $entity, 'ids' => $ids, 'fields' => $fields]])[0] : [];
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

    public function any(string $entity, string $storeViewCode, array $alternatives): array
    {
        return $this->batch($storeViewCode, [['entity' => $entity, 'any' => $alternatives]])[0];
    }

    /**
     * Every read is a search: a multi-get reads every document on its own and costs three
     * times as much for a few hundred ids. Declared fields come from doc values without the
     * source, which spares the parse of every document; any other field filters the source.
     */
    public function batch(string $storeViewCode, array $reads): array
    {
        $searches = [];
        $fromDocValues = [];
        foreach ($reads as $i => $read) {
            $index = $this->client->indexName($read['entity'], $storeViewCode);
            if (isset($read['any'])) {
                $searches[$i] = [$index, ['size' => self::WINDOW, 'query' => ['bool' => [
                    'should' => array_map(fn(array $filter) => ['bool' => ['filter' => $this->terms($filter)]], $read['any']),
                    'minimum_should_match' => 1,
                ]]]];
                continue;
            }
            $fields = $read['fields'] ?? [];
            $fromDocValues[$i] = $fields && !array_diff($fields, array_keys($this->mappings->fields($read['entity'])));
            $searches[$i] = [$index, [
                'size' => count($read['ids']),
                'query' => ['bool' => ['filter' => [['ids' => ['values' => array_values(array_map('strval', $read['ids']))]]]]],
                'track_total_hits' => false,
            ] + ($fromDocValues[$i] ? ['_source' => false, 'docvalue_fields' => $fields] : ['_source' => $fields ?: true])];
        }
        $results = [];
        foreach ($this->client->multiSearch(array_values($searches)) as $i => $response) {
            $documents = [];
            foreach ($response['hits']['hits'] ?? [] as $hit) {
                $documents[$hit['_id']] = ($fromDocValues[$i] ?? false)
                    ? array_map(static fn(array $values) => count($values) === 1 ? $values[0] : $values, $hit['fields'] ?? [])
                    : $hit['_source'];
            }
            $results[$i] = $documents;
        }

        return $results;
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
