<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Feed\Applier;

use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use GraphCommerce\CatalogStorefrontApi\Feed\FeedApplierInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Feed\RatingId;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Keeps one entry per review on the product document of every store view:
 * the review's title, text, nickname, date and its votes as rating id to
 * value where the review is visible in that store view, null where it is
 * not or once it is deleted. The read side aggregates and pages, so
 * batches need not carry all reviews of a product.
 */
class Reviews implements FeedApplierInterface
{
    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly StoreManagerInterface $storeManager,
        private readonly RatingId $ratingId,
    ) {
    }

    public function apply(array $rows): void
    {
        $stores = array_map(static fn($store) => $store->getCode(), $this->storeManager->getStores());
        $upserts = [];
        foreach ($rows as $row) {
            if (empty($row['reviewId']) || empty($row['productId'])) {
                continue;
            }
            $votes = [];
            foreach ((array)($row['ratings'] ?? []) as $rating) {
                $votes[$this->ratingId->decode((string)$rating['ratingId'])] = (int)$rating['value'];
            }
            foreach ($stores as $store) {
                $visible = empty($row['deleted']) && in_array($store, (array)($row['visibility'] ?? []), true);
                $upserts[$store][(int)$row['productId']]['reviews']['r' . $row['reviewId']] = $visible
                    ? [
                        'votes' => $votes,
                        'title' => $row['title'] ?? null,
                        'text' => $row['text'] ?? null,
                        'nickname' => $row['nickname'] ?? null,
                        'createdAt' => $row['createdAt'] ?? null,
                    ]
                    : null;
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert($store, $documents);
        }
    }
}
