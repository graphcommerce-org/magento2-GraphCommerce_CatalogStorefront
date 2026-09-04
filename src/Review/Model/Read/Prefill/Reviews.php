<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Read\Prefill;

use GraphCommerce\CatalogStorefrontApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontApi\Read\PrefillRequest;
use GraphCommerce\CatalogStorefrontReview\Model\Read\RatingMetadata;
use Magento\Review\Model\Review\Config as ReviewsConfig;

/**
 * rating_summary and review_count from the reviews slice: the average of the
 * vote percents over each rating's scale, and the number of visible reviews.
 * With reviews disabled both read as none.
 */
class Reviews implements PrefillerInterface
{
    public function __construct(
        private readonly ReviewsConfig $reviewsConfig,
        private readonly RatingMetadata $ratingMetadata,
    ) {
    }

    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('rating_summary', 'review_count')) {
            return [];
        }
        $enabled = $this->reviewsConfig->isEnabled();
        $storeViewCode = $request->store->getCode();
        $output = [];
        foreach (array_keys($models) as $id) {
            $reviews = $enabled ? array_filter((array)($documents[$id]['reviews'] ?? [])) : [];
            $percents = [];
            foreach ($reviews as $review) {
                foreach ((array)($review['votes'] ?? []) as $ratingId => $value) {
                    $scale = $this->ratingMetadata->scale($storeViewCode, (int)$ratingId);
                    if ($scale) {
                        $percents[] = (int)$value / $scale * 100;
                    }
                }
            }
            $output[$id] = [
                'rating_summary' => $percents ? (float)round(array_sum($percents) / count($percents)) : 0.0,
                'review_count' => count($reviews),
            ];
        }

        return $output;
    }
}
