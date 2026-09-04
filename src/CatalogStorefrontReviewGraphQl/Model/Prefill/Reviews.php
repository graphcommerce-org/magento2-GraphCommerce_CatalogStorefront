<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewGraphQl\Model\Prefill;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;
use GraphCommerce\CatalogStorefrontReview\Model\Document\Writer\Reviews as ReviewDocuments;
use Magento\Review\Model\Review\Config as ReviewsConfig;

/**
 * rating_summary and review_count, computed by the store over the review
 * documents of the page's products in one aggregation: the average vote
 * percent and the count of the visible reviews. With reviews disabled both
 * read as none.
 */
class Reviews implements PrefillerInterface
{
    public function __construct(
        private readonly ReviewsConfig $reviewsConfig,
        private readonly MetadataDocumentStorageInterface $reviews,
    ) {
    }

    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('rating_summary', 'review_count')) {
            return [];
        }
        $stats = $this->reviewsConfig->isEnabled()
            ? $this->reviews->stats(
                ReviewDocuments::ENTITY,
                $request->store->getCode(),
                'productId',
                array_map('strval', array_keys($models)),
                'percents'
            )
            : [];
        $output = [];
        foreach (array_keys($models) as $id) {
            $summary = $stats[(string)$id] ?? null;
            $output[$id] = [
                'rating_summary' => isset($summary['avg']) ? (float)round($summary['avg']) : 0.0,
                'review_count' => (int)($summary['count'] ?? 0),
            ];
        }

        return $output;
    }
}
