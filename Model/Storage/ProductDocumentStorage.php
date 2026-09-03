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
     * Minimum and maximum regular and final price over the variants of each
     * parent for one customer group, once over the salable enabled variants and
     * once over all enabled variants, in one request for the whole set. A
     * variant without a price for the group does not count.
     *
     * @param array<int, int[]> $variantIdsByParent
     * @return array<int, array{salable: ?array, all: ?array}> ranges as [minRegular, minFinal, maxRegular, maxFinal]
     */
    public function priceRanges(string $storeViewCode, array $variantIdsByParent, string $groupKey): array
    {
        $stats = [
            'minRegular' => ['min' => ['field' => 'priceIndex.' . $groupKey . '.regular']],
            'minFinal' => ['min' => ['field' => 'priceIndex.' . $groupKey . '.final']],
            'maxRegular' => ['max' => ['field' => 'priceIndex.' . $groupKey . '.regular']],
            'maxFinal' => ['max' => ['field' => 'priceIndex.' . $groupKey . '.final']],
        ];
        $enabled = ['term' => ['status' => 'Enabled']];
        $aggregations = [];
        foreach ($variantIdsByParent as $parentId => $variantIds) {
            $aggregations['p' . $parentId] = [
                'filter' => ['ids' => ['values' => array_map('strval', $variantIds)]],
                'aggs' => [
                    'salable' => ['filter' => ['bool' => ['filter' => [$enabled, ['term' => ['stock.isSalable' => true]]]]], 'aggs' => $stats],
                    'all' => ['filter' => $enabled, 'aggs' => $stats],
                ],
            ];
        }
        $result = $this->query->aggregate(
            $this->aliasName($storeViewCode),
            ['ids' => ['values' => array_map('strval', array_merge(...array_values($variantIdsByParent)))]],
            $aggregations
        );

        $ranges = [];
        foreach (array_keys($variantIdsByParent) as $parentId) {
            foreach (['salable', 'all'] as $mode) {
                $bucket = $result['p' . $parentId][$mode] ?? [];
                $ranges[$parentId][$mode] = isset($bucket['minFinal']['value'])
                    ? [
                        (float)$bucket['minRegular']['value'],
                        (float)$bucket['minFinal']['value'],
                        (float)$bucket['maxRegular']['value'],
                        (float)$bucket['maxFinal']['value'],
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
