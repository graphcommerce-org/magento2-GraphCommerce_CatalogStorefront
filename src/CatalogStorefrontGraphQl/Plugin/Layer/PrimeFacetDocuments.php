<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\LayerBuilder;
use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\AggregationValueInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Fetches what the layer builders will ask for in one request before they
 * run: the attribute documents of the aggregated option ids (the price
 * bucket's range keys included, since the price builder asks for them too)
 * and the names of the aggregated categories. Core's builders ask one after
 * the other, which cost a round trip each.
 */
class PrimeFacetDocuments
{
    private const CATEGORY_BUCKET = 'category_bucket';

    public function __construct(
        private readonly FacetDocuments $facets,
        private readonly StoreManagerInterface $storeManager,
        private readonly Mode $mode,
        private readonly Strict $strict,
    ) {
    }

    public function beforeBuild(LayerBuilder $subject, AggregationInterface $aggregation, ?int $storeId): void
    {
        if (!$this->mode->documents()) {
            return;
        }
        $optionIds = [];
        $codes = [];
        $categoryIds = [];
        foreach ($aggregation->getBuckets() as $bucket) {
            $values = array_map(static fn(AggregationValueInterface $value) => $value->getValue(), $bucket->getValues());
            if ($bucket->getName() === self::CATEGORY_BUCKET) {
                $categoryIds = array_map('intval', $values);
            } else {
                $codes[] = preg_replace('~_bucket$~', '', $bucket->getName());
                $optionIds = array_merge($optionIds, $values);
            }
        }
        if (!$optionIds && !$categoryIds) {
            return;
        }
        try {
            $this->facets->prime($this->storeManager->getStore($storeId)->getCode(), $optionIds, $codes, $categoryIds);
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }
    }
}
