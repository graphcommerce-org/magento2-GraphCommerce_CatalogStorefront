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

    /** The engine's result window: a read of more ids is a multi-search of this many ids per search. */
    private const WINDOW = 10000;

    public function __construct(
        private readonly Client $client,
        private readonly Index $index,
        private readonly ChildPriceRanges $childPriceRanges,
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

    /**
     * A search by id: a multi-get reads every document on its own and costs three times as
     * much for a few hundred ids. The writers read through stored(), which stays a multi-get
     * because it must see the batch's own writes.
     */
    public function get(string $storeViewCode, array $ids, array $fields = []): array
    {
        if (!$ids) {
            return [];
        }
        $index = $this->indexName($storeViewCode);
        $searches = $this->idSearches($ids, ['_source' => $fields ?: true]);

        return $this->hits($this->client->multiSearch(array_map(static fn(array $search) => [$index, $search], $searches)));
    }

    public function findBySku(string $storeViewCode, array $skus): array
    {
        return $this->bySku($this->indexName($storeViewCode), $skus);
    }

    public function stored(string $storeViewCode, array $ids, array $fields = []): array
    {
        return $this->byId($this->index->ensure(self::ENTITY, $storeViewCode), $ids, $fields);
    }

    public function storedBySku(string $storeViewCode, array $skus): array
    {
        return $this->bySku($this->index->ensure(self::ENTITY, $storeViewCode), $skus);
    }

    private function byId(string $index, array $ids, array $fields): array
    {
        $documents = [];
        foreach ($ids ? $this->client->get($index, $ids, $fields) : [] as $id => $document) {
            $documents[(int)$id] = $document;
        }

        return $documents;
    }

    private function bySku(string $index, array $skus): array
    {
        if (!$skus) {
            return [];
        }
        $response = $this->client->search($index, [
            'size' => count($skus),
            'query' => ['terms' => ['sku' => array_values($skus)]],
        ]);
        $documents = [];
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $documents[(int)$hit['_id']] = $hit['_source'];
        }

        return $documents;
    }

    /**
     * The page's documents whole: filtering the source costs OpenSearch more than the bytes it saves.
     */
    public function listing(string $storeViewCode, array $ids, ?string $groupKey): array
    {
        $searches = $this->idSearches($ids);
        $reads = count($searches);
        if ($groupKey !== null) {
            $searches = array_merge($searches, $this->priceSearches($ids, $groupKey));
        }
        $index = $this->indexName($storeViewCode);
        $responses = $this->client->multiSearch(array_map(static fn(array $search) => [$index, $search], $searches));

        return [
            $this->hits(array_slice($responses, 0, $reads)),
            $groupKey === null ? [] : $this->parsePriceData(array_slice($responses, $reads)),
        ];
    }

    /**
     * The documents of the ids, one search per window of ids.
     *
     * @return array[] search bodies
     */
    private function idSearches(array $ids, array $extra = []): array
    {
        return array_map(
            static fn(array $chunk) => ['size' => count($chunk), 'query' => ['ids' => ['values' => $chunk]]] + $extra,
            array_chunk(array_values(array_map('strval', $ids)), self::WINDOW)
        );
    }

    /**
     * @return array<int, array> the documents of the responses' hits by id
     */
    private function hits(array $responses): array
    {
        $documents = [];
        foreach ($responses as $response) {
            foreach ($response['hits']['hits'] ?? [] as $hit) {
                $documents[(int)$hit['_id']] = $hit['_source'];
            }
        }

        return $documents;
    }

    public function priceData(string $storeViewCode, array $ids, string $groupKey): array
    {
        return $this->parsePriceData(
            $this->client->multiSearch(array_map(
                fn(array $search) => [$this->indexName($storeViewCode), $search],
                $this->priceSearches($ids, $groupKey)
            ))
        );
    }

    private function priceSearches(array $parentIds, string $groupKey): array
    {
        $parentIds = array_values(array_map('strval', $parentIds));

        return array_merge(
            [
                $this->childPriceRanges->build('parentIds', $parentIds, $groupKey),
                $this->childPriceRanges->build('groupedParentIds', $parentIds, $groupKey),
                [
                    'size' => 1000,
                    'query' => ['bool' => ['filter' => [
                        ['terms' => ['bundleParentIds' => $parentIds]],
                        ['term' => ['status' => 'Enabled']],
                    ]]],
                    '_source' => ['sku', 'bundleParentIds', 'stock.isSalable', 'priceIndex', 'taxClassId'],
                ],
            ],
            array_map(
                static fn(array $search) => [
                    'size' => $search['size'],
                    'query' => ['bool' => ['filter' => [
                        $search['query'],
                        ['terms' => ['type' => ['bundle', 'bundle_fixed']]],
                    ]]],
                    '_source' => ['optionsV2', 'shopperInputOptions'],
                ],
                $this->idSearches($parentIds)
            )
        );
    }

    private function parsePriceData(array $responses): array
    {
        $selections = [];
        foreach ($responses[2]['hits']['hits'] ?? [] as $hit) {
            foreach ((array)($hit['_source']['bundleParentIds'] ?? []) as $parentId) {
                $selections[(int)$parentId][$hit['_source']['sku']] = $hit['_source'];
            }
        }

        return [
            'configurable' => $this->childPriceRanges->parse($responses[0] ?? []),
            'grouped' => $this->childPriceRanges->parse($responses[1] ?? []),
            'bundle' => $selections,
            'bundleOptions' => $this->hits(array_slice($responses, 3)),
        ];
    }

    private function indexName(string $storeViewCode): string
    {
        return $this->client->indexName(self::ENTITY, $storeViewCode);
    }
}
