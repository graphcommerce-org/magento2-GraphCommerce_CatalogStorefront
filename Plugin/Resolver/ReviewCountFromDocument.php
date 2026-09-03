<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Review\Model\Review\Config as ReviewsConfig;
use Magento\ReviewGraphQl\Model\Resolver\Product\ReviewCount;

/**
 * Serves review_count from the reviews slice of the document: the number of
 * reviews visible in the store view.
 */
class ReviewCountFromDocument
{
    public function __construct(
        private readonly ReviewsConfig $reviewsConfig,
    ) {
    }

    public function aroundResolve(
        ReviewCount $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): int {
        $document = ($value['model'] ?? null)?->getData(ProductModelBuilder::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($field, $context, $info, $value, $args);
        }

        return $this->reviewsConfig->isEnabled() ? count(array_filter((array)($document['reviews'] ?? []))) : 0;
    }
}
