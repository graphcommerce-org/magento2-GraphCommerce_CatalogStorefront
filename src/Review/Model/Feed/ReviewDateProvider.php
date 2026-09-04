<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Feed;

use Magento\Framework\App\ResourceConnection;

/**
 * Adds the creation date to a reviews feed row; the exporter leaves it out
 * and the reviews field lists it.
 */
class ReviewDateProvider
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function get(array $values): array
    {
        $ids = array_unique(array_map(static fn(array $value) => (int)$value['reviewId'], $values));
        if (!$ids) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $dates = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('review'), ['review_id', 'created_at'])
                ->where('review_id IN (?)', $ids)
        );
        $output = [];
        foreach ($values as $value) {
            $output[$value['reviewId']] = [
                'reviewId' => $value['reviewId'],
                'createdAt' => $dates[(int)$value['reviewId']] ?? null,
            ];
        }

        return $output;
    }
}
