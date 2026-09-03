<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Review\Model\Review\Config as ReviewsConfig;
use Magento\ReviewGraphQl\Model\Resolver\Product\RatingSummary;

/**
 * Serves rating_summary from the reviews slice of the document: the rounded
 * average of all vote percents of the reviews visible in the store view, as
 * the core review aggregate computes it.
 */
class RatingSummaryFromDocument
{
    public function __construct(
        private readonly ReviewsConfig $reviewsConfig,
    ) {
    }

    public function aroundResolve(
        RatingSummary $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): float {
        $document = ($value['model'] ?? null)?->getData(ProductModelBuilder::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($field, $context, $info, $value, $args);
        }
        if (!$this->reviewsConfig->isEnabled()) {
            return 0.0;
        }
        $percents = array_merge([], ...array_values(array_filter((array)($document['reviews'] ?? []))));

        return $percents ? (float)round(array_sum($percents) / count($percents)) : 0.0;
    }
}
