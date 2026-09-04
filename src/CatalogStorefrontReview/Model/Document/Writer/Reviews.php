<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Document\Writer;

use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Document\RatingId;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Keeps one review document per review and store view where the review is
 * visible, with its votes and their percents over the rating's scale, and
 * removes it where it is not or once it is deleted. The product document
 * carries nothing about reviews: the summary and the review page are
 * queries over the review documents.
 */
class Reviews implements FeedWriterInterface
{
    public const ENTITY = 'review';

    public function __construct(
        private readonly MetadataDocumentStorageInterface $reviews,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly RatingId $ratingId,
    ) {
    }

    public function write(array $rows): void
    {
        $stores = array_map(static fn($store) => $store->getCode(), $this->storeManager->getStores());
        $connection = $this->resourceConnection->getConnection();
        $scales = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('rating_option'), ['rating_id', 'COUNT(*)'])
                ->group('rating_id')
        );

        $upserts = [];
        $deletes = [];
        foreach ($rows as $row) {
            if (empty($row['reviewId']) || empty($row['productId'])) {
                continue;
            }
            $votes = [];
            $percents = [];
            foreach ((array)($row['ratings'] ?? []) as $rating) {
                $ratingId = $this->ratingId->decode((string)$rating['ratingId']);
                $votes[$ratingId] = (int)$rating['value'];
                if (!empty($scales[$ratingId])) {
                    $percents[] = (int)$rating['value'] / (int)$scales[$ratingId] * 100;
                }
            }
            foreach ($stores as $store) {
                if (empty($row['deleted']) && in_array($store, (array)($row['visibility'] ?? []), true)) {
                    $upserts[$store][(int)$row['reviewId']] = [
                        'reviewId' => (int)$row['reviewId'],
                        'productId' => (string)(int)$row['productId'],
                        'title' => $row['title'] ?? null,
                        'text' => $row['text'] ?? null,
                        'nickname' => $row['nickname'] ?? null,
                        'createdAt' => $row['createdAt'] ?? null,
                        'votes' => $votes,
                        'percents' => $percents,
                    ];
                } else {
                    $deletes[$store][] = (int)$row['reviewId'];
                }
            }
        }
        foreach ($stores as $store) {
            $this->reviews->upsert(self::ENTITY, $store, $upserts[$store] ?? []);
            $this->reviews->delete(self::ENTITY, $store, $deletes[$store] ?? []);
        }
    }
}
