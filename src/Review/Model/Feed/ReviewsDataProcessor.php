<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Feed;

use Magento\DataExporter\Export\DataProcessorInterface;
use Magento\DataExporter\Export\Request\Info;
use Magento\DataExporter\Export\Request\Node;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\ProductReviewDataExporter\Model\Provider\ProductReviews;

/**
 * Lets the reviews feed export during indexing. Immediate export requires a
 * DataProcessorInterface provider, and the reviews provider only offers the
 * batch get() form, so this adapts it. Declared as the provider of the
 * Export.reviews field in etc/et_schema.xml.
 */
class ReviewsDataProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly ProductReviews $productReviews,
    ) {
    }

    public function execute(
        array $arguments,
        callable $dataProcessorCallback,
        FeedIndexMetadata $metadata,
        ?Node $node = null,
        ?Info $info = null
    ): void {
        $dataProcessorCallback($this->productReviews->get($arguments));
    }
}
