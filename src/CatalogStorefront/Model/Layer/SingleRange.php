<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Layer;

use Magento\Framework\Search\Dynamic\Algorithm\AlgorithmInterface;
use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\Dynamic\EntityStorage;
use Magento\Framework\Search\Request\BucketInterface;

/**
 * The `single` price navigation step calculation: one range from the lowest
 * to the highest price of the result, the bounds a price slider reads. Core's
 * modes split that range into intervals with two or three more queries.
 */
class SingleRange implements AlgorithmInterface
{
    public const METHOD = 'single';

    public function __construct(
        private readonly DataProviderInterface $dataProvider,
    ) {
    }

    public function getItems(BucketInterface $bucket, array $dimensions, EntityStorage $entityStorage): array
    {
        return $entityStorage->getSource() ? self::range($this->dataProvider->getAggregations($entityStorage)) : [];
    }

    /**
     * The range of an extended stats aggregation, in the shape core's data
     * provider prepares (from, to, count).
     */
    public static function range(array $stats): array
    {
        if (empty($stats['count'])) {
            return [];
        }

        return [[
            'from' => round((float)$stats['min'], 2),
            'to' => round((float)$stats['max'], 2),
            'count' => (int)$stats['count'],
        ]];
    }
}
