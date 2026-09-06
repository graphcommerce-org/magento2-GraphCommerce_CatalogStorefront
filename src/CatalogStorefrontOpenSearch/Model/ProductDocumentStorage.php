<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;

/**
 * Product documents per store view, one document per product, assembled from
 * feed slices: the products feed writes the base fields, the prices and stock
 * feeds patch their own key into the same document. The composite price data
 * aggregates the nested price index of the children by customer group.
 */
class ProductDocumentStorage implements ProductDocumentStorageInterface
{
    private const ENTITY = 'product';

    public function __construct(
        private readonly Client $client,
        private readonly Index $index,
    ) {
    }

    public function upsert(string $storeViewCode, array $documents): void
    {
        if ($documents) {
            $this->client->upsert($this->index->ensure(self::ENTITY, $storeViewCode), $documents);
        }
    }

    public function delete(string $storeViewCode, array $ids): void
    {
        if ($ids) {
            $this->client->delete($this->index->ensure(self::ENTITY, $storeViewCode), $ids);
        }
    }

    public function stage(string $storeViewCode): void
    {
        $this->index->stage(self::ENTITY, $storeViewCode);
    }

    public function promote(string $storeViewCode): void
    {
        $this->index->promote(self::ENTITY, $storeViewCode);
    }

    public function updateLists(string $storeViewCode, array $changes): void
    {
        if ($changes) {
            $this->client->updateLists($this->index->ensure(self::ENTITY, $storeViewCode), $changes);
        }
    }

    public function count(string $storeViewCode): int
    {
        return $this->client->count($this->indexName($storeViewCode));
    }

    public function get(string $storeViewCode, array $ids, array $fields = []): array
    {
        $documents = [];
        foreach ($ids ? $this->client->get($this->indexName($storeViewCode), $ids, $fields) : [] as $id => $document) {
            $documents[(int)$id] = $document;
        }

        return $documents;
    }

    public function findBySku(string $storeViewCode, array $skus): array
    {
        if (!$skus) {
            return [];
        }
        $response = $this->client->search($this->indexName($storeViewCode), [
            'size' => count($skus),
            'query' => ['terms' => ['sku' => array_values($skus)]],
        ]);
        $documents = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $documents[(int)$hit['_id']] = $hit['_source'];
        }

        return $documents;
    }

    public function listing(string $storeViewCode, array $ids, array $sourceExcludes, ?string $groupKey): array
    {
        $searches = [[
            'size' => count($ids),
            'query' => ['ids' => ['values' => array_values(array_map('strval', $ids))]],
            '_source' => $sourceExcludes ? ['excludes' => $sourceExcludes] : true,
        ]];
        if ($groupKey !== null) {
            $searches = array_merge($searches, $this->priceSearches($ids, $groupKey));
        }
        $responses = $this->client->multiSearch($this->indexName($storeViewCode), $searches);
        $documents = [];
        foreach ($responses[0]['hits']['hits'] ?? [] as $hit) {
            $documents[(int)$hit['_id']] = $hit['_source'];
        }

        return [$documents, $groupKey === null ? [] : $this->parsePriceData(array_slice($responses, 1))];
    }

    public function priceData(string $storeViewCode, array $ids, string $groupKey): array
    {
        return $this->parsePriceData(
            $this->client->multiSearch($this->indexName($storeViewCode), $this->priceSearches($ids, $groupKey))
        );
    }

    private function priceSearches(array $parentIds, string $groupKey): array
    {
        $parentIds = array_values(array_map('strval', $parentIds));

        return [
            $this->rangeAggregation('parentIds', $parentIds, $groupKey),
            $this->rangeAggregation('groupedParentIds', $parentIds, $groupKey),
            [
                'size' => 1000,
                'query' => ['bool' => ['filter' => [
                    ['terms' => ['bundleParentIds' => $parentIds]],
                    ['term' => ['status' => 'Enabled']],
                ]]],
                '_source' => ['sku', 'bundleParentIds', 'stock.isSalable', 'priceIndex', 'taxClassId'],
            ],
            [
                'size' => count($parentIds),
                'query' => ['bool' => ['filter' => [
                    ['ids' => ['values' => $parentIds]],
                    ['terms' => ['type' => ['bundle', 'bundle_fixed']]],
                ]]],
                '_source' => ['optionsV2', 'shopperInputOptions'],
            ],
        ];
    }

    /**
     * Per parent, the minimum and maximum regular and final price of the
     * children's price index entries for the group, over the salable children
     * and over all enabled children.
     */
    private function rangeAggregation(string $parentField, array $parentIds, string $groupKey): array
    {
        $group = ['nested' => ['path' => 'priceIndex'], 'aggs' => ['group' => [
            'filter' => ['term' => ['priceIndex.group' => $groupKey]],
            'aggs' => [
                'minRegular' => ['min' => ['field' => 'priceIndex.regular']],
                'minFinal' => ['min' => ['field' => 'priceIndex.final']],
                'maxRegular' => ['max' => ['field' => 'priceIndex.regular']],
                'maxFinal' => ['max' => ['field' => 'priceIndex.final']],
            ],
        ]]];

        return [
            'size' => 0,
            'query' => ['bool' => ['filter' => [
                ['terms' => [$parentField => $parentIds]],
                ['term' => ['status' => 'Enabled']],
            ]]],
            'aggs' => ['parents' => [
                'terms' => ['field' => $parentField, 'size' => count($parentIds), 'include' => $parentIds],
                'aggs' => [
                    'salable' => ['filter' => ['term' => ['stock.isSalable' => true]], 'aggs' => ['prices' => $group]],
                    'all' => ['filter' => ['match_all' => new \stdClass()], 'aggs' => ['prices' => $group]],
                ],
            ]],
        ];
    }

    private function parsePriceData(array $responses): array
    {
        $selections = [];
        foreach ($responses[2]['hits']['hits'] ?? [] as $hit) {
            foreach ((array)($hit['_source']['bundleParentIds'] ?? []) as $parentId) {
                $selections[(int)$parentId][$hit['_source']['sku']] = $hit['_source'];
            }
        }
        $bundleOptions = [];
        foreach ($responses[3]['hits']['hits'] ?? [] as $hit) {
            $bundleOptions[(int)$hit['_id']] = $hit['_source'];
        }

        return [
            'configurable' => $this->parseRanges($responses[0] ?? []),
            'grouped' => $this->parseRanges($responses[1] ?? []),
            'bundle' => $selections,
            'bundleOptions' => $bundleOptions,
        ];
    }

    private function parseRanges(array $response): array
    {
        $ranges = [];
        foreach ($response['aggregations']['parents']['buckets'] ?? [] as $bucket) {
            foreach (['salable', 'all'] as $mode) {
                $stats = $bucket[$mode]['prices']['group'];
                $ranges[(int)$bucket['key']][$mode] = isset($stats['minFinal']['value'])
                    ? [
                        (float)$stats['minRegular']['value'],
                        (float)$stats['minFinal']['value'],
                        (float)$stats['maxRegular']['value'],
                        (float)$stats['maxFinal']['value'],
                    ]
                    : null;
            }
        }

        return $ranges;
    }

    private function indexName(string $storeViewCode): string
    {
        return $this->client->indexName(self::ENTITY, $storeViewCode);
    }
}
