<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefrontReview\Model\Document\RatingId;
use Magento\Framework\App\ResourceConnection;

/**
 * Adds the value scale of every rating a review votes on to the review row.
 * A vote's percent is its value over that scale, which the review document
 * carries next to the votes.
 */
class RatingScales
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly RatingId $ratingId,
    ) {
    }

    public function get(array $values): array
    {
        $connection = $this->resourceConnection->getConnection();
        $scales = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('rating_option'), ['rating_id', 'COUNT(*)'])
                ->group('rating_id')
        );
        $output = [];
        foreach ($values as $value) {
            foreach ((array)($value['ratings'] ?? []) as $rating) {
                $ratingId = $this->ratingId->decode((string)$rating['ratingId']);
                if (empty($scales[$ratingId])) {
                    continue;
                }
                $output[$value['reviewId'] . '_' . $ratingId] = [
                    'reviewId' => $value['reviewId'],
                    'ratingScales' => ['ratingId' => $ratingId, 'scale' => (int)$scales[$ratingId]],
                ];
            }
        }

        return $output;
    }
}
