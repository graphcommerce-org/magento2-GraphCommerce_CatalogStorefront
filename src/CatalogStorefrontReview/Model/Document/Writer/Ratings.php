<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Document\Writer;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Document\RatingId;

/**
 * The rating metadata feed lands as one rating document per store view,
 * keyed by rating id.
 */
class Ratings implements FeedWriterInterface
{
    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly RatingId $ratingId,
    ) {
    }

    public function write(array $rows): void
    {
        $upserts = [];
        foreach ($rows as $row) {
            if (empty($row['ratingId']) || empty($row['storeViewCode'])) {
                continue;
            }
            $upserts[$row['storeViewCode']][$this->ratingId->decode((string)$row['ratingId'])] = $row;
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert('rating', $store, $documents);
        }
    }
}
