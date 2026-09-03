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
        return $this->query->getEntries($this->aliasName($storeViewCode), self::ENTITY, $ids, $fields);
    }

    /**
     * @param string[] $skus
     */
    public function findBySku(string $storeViewCode, array $skus): EntryIteratorInterface
    {
        return $this->query->searchFilteredEntries($this->aliasName($storeViewCode), self::ENTITY, ['sku' => $skus]);
    }

    /**
     * The documents of a listing page and the configurable price ranges of the
     * page, in one request: a search by id with the heavy source fields the
     * query does not need left out, and the price aggregation over the
     * variants of every listed parent.
     *
     * @param int[] $ids
     * @param string[] $sourceExcludes document keys to leave out
     * @param string|null $groupKey price group key, null when no ranges are needed
     * @return array{0: array<int, array>, 1: array} documents by id, ranges by parent id
     */
    public function listing(string $storeViewCode, array $ids, array $sourceExcludes, ?string $groupKey): array
    {
        $searches = [[
            'size' => count($ids),
            'query' => ['ids' => ['values' => array_map('strval', $ids)]],
            '_source' => $sourceExcludes ? ['excludes' => $sourceExcludes] : true,
        ]];
        if ($groupKey !== null) {
            $searches[] = $this->priceAggregation($ids, $groupKey);
        }
        $responses = $this->query->multiSearch($this->aliasName($storeViewCode), $searches);

        $documents = [];
        foreach ($responses[0]['hits']['hits'] ?? [] as $hit) {
            $documents[(int)$hit['_id']] = $hit['_source'];
        }

        return [$documents, $groupKey === null ? [] : $this->parseRanges($responses[1] ?? [])];
    }

    /**
     * The configurable price ranges of the given parents for one customer group.
     *
     * @param int[] $parentIds
     * @return array<int, array{salable: ?array, all: ?array}> ranges as [minRegular, minFinal, maxRegular, maxFinal]
     */
    public function priceRanges(string $storeViewCode, array $parentIds, string $groupKey): array
    {
        $search = $this->priceAggregation($parentIds, $groupKey);

        return $this->parseRanges(
            ['aggregations' => $this->query->aggregate($this->aliasName($storeViewCode), $search['query'], $search['aggs'])]
        );
    }

    /**
     * Minimum and maximum regular and final price over the enabled variants
     * of each parent, once over the salable variants and once over all. A
     * variant without a price for the group does not count.
     *
     * @param int[] $parentIds
     */
    private function priceAggregation(array $parentIds, string $groupKey): array
    {
        $parentIds = array_map('strval', $parentIds);
        $stats = [
            'minRegular' => ['min' => ['field' => 'priceIndex.' . $groupKey . '.regular']],
            'minFinal' => ['min' => ['field' => 'priceIndex.' . $groupKey . '.final']],
            'maxRegular' => ['max' => ['field' => 'priceIndex.' . $groupKey . '.regular']],
            'maxFinal' => ['max' => ['field' => 'priceIndex.' . $groupKey . '.final']],
        ];

        return [
            'size' => 0,
            'query' => ['bool' => ['filter' => [
                ['terms' => ['parentIds' => $parentIds]],
                ['term' => ['status' => 'Enabled']],
            ]]],
            'aggs' => ['parents' => [
                'terms' => ['field' => 'parentIds', 'size' => count($parentIds), 'include' => $parentIds],
                'aggs' => [
                    'salable' => ['filter' => ['term' => ['stock.isSalable' => true]], 'aggs' => $stats],
                    'all' => ['filter' => ['match_all' => new \stdClass()], 'aggs' => $stats],
                ],
            ]],
        ];
    }

    /**
     * @return array<int, array{salable: ?array, all: ?array}>
     */
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
