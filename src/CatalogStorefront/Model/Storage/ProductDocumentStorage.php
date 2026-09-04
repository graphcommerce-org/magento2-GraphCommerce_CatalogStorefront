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
     * Missing ids are skipped. A field list limits the returned source.
     *
     * @param int[] $ids
     * @param string[] $fields
     */
    public function get(string $storeViewCode, array $ids, array $fields = ['*']): EntryIteratorInterface
    {
        $this->ensureIndex($storeViewCode);

        return $this->query->getEntries($this->aliasName($storeViewCode), self::ENTITY, $ids, $fields);
    }

    /**
     * @param string[] $skus
     */
    public function findBySku(string $storeViewCode, array $skus): EntryIteratorInterface
    {
        $this->ensureIndex($storeViewCode);

        return $this->query->searchFilteredEntries($this->aliasName($storeViewCode), self::ENTITY, ['sku' => $skus]);
    }

    /**
     * The documents of a listing page and, when a group key is given, the
     * price data of the page's composite products, in one multi-search.
     *
     * @return array{0: array[], 1: array} documents keyed by id, price data as priceData() returns it
     */
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
        $responses = $this->query->multiSearch($this->aliasName($storeViewCode), $searches);
        $documents = [];
        foreach ($responses[0]['hits']['hits'] ?? [] as $hit) {
            $documents[(int)$hit['_id']] = $hit['_source'];
        }

        return [$documents, $groupKey === null ? [] : $this->parsePriceData(array_slice($responses, 1))];
    }

    /**
     * Price data of composite products: the configurable and grouped price
     * ranges per parent id (over salable and over all children), the bundle
     * selection documents per parent id and sku, and the bundles' option
     * slices, which a listing fetch leaves out of the product documents.
     *
     * @return array{configurable: array, grouped: array, bundle: array, bundleOptions: array}
     */
    public function priceData(string $storeViewCode, array $ids, string $groupKey): array
    {
        return $this->parsePriceData(
            $this->query->multiSearch($this->aliasName($storeViewCode), $this->priceSearches($ids, $groupKey))
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
                '_source' => ['sku', 'bundleParentIds', 'stock.isSalable', 'priceIndex.' . $groupKey],
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

    private function rangeAggregation(string $parentField, array $parentIds, string $groupKey): array
    {
        $stats = [
            'minRegular' => ['min' => ['field' => 'priceIndex.' . $groupKey . '.regular']],
            'minFinal' => ['min' => ['field' => 'priceIndex.' . $groupKey . '.final']],
            'maxRegular' => ['max' => ['field' => 'priceIndex.' . $groupKey . '.regular']],
            'maxFinal' => ['max' => ['field' => 'priceIndex.' . $groupKey . '.final']],
        ];

        return [
            'size' => 0,
            'query' => ['bool' => ['filter' => [
                ['terms' => [$parentField => $parentIds]],
                ['term' => ['status' => 'Enabled']],
            ]]],
            'aggs' => ['parents' => [
                'terms' => ['field' => $parentField, 'size' => count($parentIds), 'include' => $parentIds],
                'aggs' => [
                    'salable' => ['filter' => ['term' => ['stock.isSalable' => true]], 'aggs' => $stats],
                    'all' => ['filter' => ['match_all' => new \stdClass()], 'aggs' => $stats],
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
                $stats = $bucket[$mode];
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
