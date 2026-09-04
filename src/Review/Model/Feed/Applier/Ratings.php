<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Feed\Applier;

use GraphCommerce\CatalogStorefront\Model\Storage\MetadataDocumentStorage;
use GraphCommerce\CatalogStorefrontApi\Feed\FeedApplierInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Feed\RatingId;

/**
 * The rating metadata feed lands as one rating document per store view,
 * keyed by rating id.
 */
class Ratings implements FeedApplierInterface
{
    public function __construct(
        private readonly MetadataDocumentStorage $ratingStorage,
        private readonly RatingId $ratingId,
    ) {
    }

    public function apply(array $rows): void
    {
        $upserts = [];
        foreach ($rows as $row) {
            if (empty($row['ratingId']) || empty($row['storeViewCode'])) {
                continue;
            }
            $upserts[$row['storeViewCode']][$this->ratingId->decode((string)$row['ratingId'])] = $row;
        }
        foreach ($upserts as $store => $documents) {
            $this->ratingStorage->upsert($store, $documents);
        }
    }
}
