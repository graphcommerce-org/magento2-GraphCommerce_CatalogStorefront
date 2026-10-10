<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Plugin;

use GraphCommerce\CatalogStorefrontSearch\Model\Config;
use Magento\Elasticsearch\SearchAdapter\Aggregation\Builder\Dynamic;
use Magento\Framework\Search\Adapter\OptionsInterface;
use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\Dynamic\EntityStorageFactory;
use Magento\Framework\Search\Request\Aggregation\DynamicBucket;
use Magento\Framework\Search\Request\BucketInterface;

/**
 * Core's `auto` price navigation step, found without a query. Core reads the
 * highest price with a stats query, then sends a histogram query per step,
 * from the largest power of ten under that price down, until a histogram has
 * two buckets. The search response already holds the lowest and the highest
 * price of the result, and a histogram has two buckets exactly when those two
 * fall in different buckets, so the step comes from the response. One
 * histogram query at that step counts the intervals, as core's last query
 * does.
 */
class AutoPriceRangeFromResponse
{
    public function __construct(
        private readonly Config $config,
        private readonly OptionsInterface $options,
        private readonly EntityStorageFactory $entityStorageFactory,
    ) {
    }

    public function aroundBuild(
        Dynamic $subject,
        \Closure $proceed,
        BucketInterface $bucket,
        array $dimensions,
        array $queryResult,
        DataProviderInterface $dataProvider
    ): array {
        $stats = $queryResult['aggregations'][$bucket->getName()] ?? null;
        if (!$bucket instanceof DynamicBucket || $bucket->getMethod() !== 'auto'
            || !isset($stats['min'], $stats['max']) || !$this->config->priceRangesFromResponse()) {
            return $proceed($bucket, $dimensions, $queryResult, $dataProvider);
        }

        $ids = array_map(
            static fn (array $hit) => $hit['_id'] ?? $hit['fields']['_id'][0] ?? null,
            $queryResult['hits']['hits'] ?? []
        );
        if ($ids === []) {
            return [];
        }

        $range = $dataProvider->getRange();
        if (!$range) {
            $maxPrice = floor((float)$stats['max']);
            $minRangePower = $this->options->get()['min_range_power'];
            $index = 1;
            do {
                $range = pow(10, strlen((string)floor($maxPrice)) - $index);
                $index++;
            } while ($range > $minRangePower
                && floor((float)$stats['min'] / $range) === floor((float)$stats['max'] / $range));
        }

        $entityStorage = $this->entityStorageFactory->create($ids);
        $data = [];
        foreach ($dataProvider->prepareData($range, $dataProvider->getAggregation($bucket, $dimensions, $range, $entityStorage)) as $value) {
            $name = "{$value['from']}_{$value['to']}";
            $value['value'] = $name;
            $data[$name] = $value;
        }

        return $data;
    }
}
