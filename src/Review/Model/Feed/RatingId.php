<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Feed;

/**
 * The exporter base64-encodes rating ids in stored feeds and hands them over
 * plain when exporting immediately.
 */
class RatingId
{
    public function decode(string $ratingId): int
    {
        return (int)(is_numeric($ratingId) ? $ratingId : base64_decode($ratingId));
    }
}
