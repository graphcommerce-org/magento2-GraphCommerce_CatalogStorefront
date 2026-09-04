<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Feed;

use Magento\DataExporter\Export\DataProcessorInterface;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\DataExporter\Export\Request\Info;
use Magento\DataExporter\Export\Request\Node;
use Magento\ProductReviewDataExporter\Model\Provider\RatingMetadata;

/**
 * Lets the rating metadata feed export during indexing: immediate export
 * requires a data processor, and the exporter's provider is a plain one.
 */
class RatingMetadataDataProcessor implements DataProcessorInterface
{
    public function __construct(
        private readonly RatingMetadata $ratingMetadata,
    ) {
    }

    public function execute(
        array $arguments,
        callable $dataProcessorCallback,
        FeedIndexMetadata $metadata,
        ?Node $node = null,
        ?Info $info = null
    ): void {
        // The indexer hands over rating_id; the provider reads ratingId.
        $dataProcessorCallback($this->ratingMetadata->get(array_map(
            static fn(array $argument) => ['ratingId' => $argument['rating_id'] ?? $argument['ratingId']],
            $arguments
        )));
    }
}
