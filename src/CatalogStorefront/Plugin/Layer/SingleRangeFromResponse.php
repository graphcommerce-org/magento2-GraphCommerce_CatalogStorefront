<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Layer\SingleRange;
use Magento\Elasticsearch\SearchAdapter\Aggregation\Builder\Dynamic;
use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\Request\Aggregation\DynamicBucket;
use Magento\Framework\Search\Request\BucketInterface;

/**
 * The search response already carries the extended stats of a dynamic bucket,
 * so the single range comes from it without a follow-up query.
 */
class SingleRangeFromResponse
{
    public function aroundBuild(
        Dynamic $subject,
        \Closure $proceed,
        BucketInterface $bucket,
        array $dimensions,
        array $queryResult,
        DataProviderInterface $dataProvider
    ): array {
        $stats = $queryResult['aggregations'][$bucket->getName()] ?? null;
        if (!$bucket instanceof DynamicBucket || $bucket->getMethod() !== SingleRange::METHOD || !isset($stats['count'])) {
            return $proceed($bucket, $dimensions, $queryResult, $dataProvider);
        }

        $data = [];
        foreach (SingleRange::range($stats) as $value) {
            $name = "{$value['from']}_{$value['to']}";
            $data[$name] = $value + ['value' => $name];
        }

        return $data;
    }
}
