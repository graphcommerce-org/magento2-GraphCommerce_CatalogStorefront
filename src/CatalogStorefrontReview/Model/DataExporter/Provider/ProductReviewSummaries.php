<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adds to a review row the summary of its product in every store view: the
 * number of approved reviews and the average over the percents of their votes,
 * as the review page computes them. A reader of the rows therefore needs no
 * aggregate of its own, and a batch that names a product carries the complete
 * answer for that product.
 */
class ProductReviewSummaries
{
    private const MAX_PRODUCTS = 10000;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function get(array $values): array
    {
        $productIds = [];
        array_walk_recursive($values, static function ($value, string $key) use (&$productIds): void {
            if ($key === 'productId' && (int)$value > 0) {
                $productIds[(int)$value] = true;
            }
        });
        $productIds = array_keys($productIds);
        if (!$productIds) {
            return [];
        }
        if (count($productIds) > self::MAX_PRODUCTS) {
            throw new \RuntimeException('A review batch names more products than the summary provider supports.');
        }
        $storeViewCodes = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeViewCodes[(int)$store->getId()] = (string)$store->getCode();
        }
        $connection = $this->resourceConnection->getConnection();
        $table = fn(string $name): string => $this->resourceConnection->getTableName($name);
        $approved = fn(): \Magento\Framework\DB\Select => $connection->select()
            ->from(['r' => $table('review')], [])
            ->join(['e' => $table('review_entity')], "e.entity_id = r.entity_id AND e.entity_code = 'product'", [])
            ->join(['rs' => $table('review_store')], 'rs.review_id = r.review_id AND rs.store_id > 0', [])
            ->where('r.status_id = ?', 1)
            ->where('r.entity_pk_value IN (?)', $productIds)
            ->group(['rs.store_id', 'r.entity_pk_value']);
        $counts = $connection->fetchAll(
            $approved()->columns(['store_id' => 'rs.store_id', 'entity_pk_value' => 'r.entity_pk_value',
                'review_count' => new \Zend_Db_Expr('COUNT(DISTINCT r.review_id)')])
        );
        $votes = $connection->fetchAll(
            $approved()
                ->join(['v' => $table('rating_option_vote')], 'v.review_id = r.review_id AND v.entity_pk_value = r.entity_pk_value', [])
                ->join(['a' => $table('rating')], 'a.rating_id = v.rating_id AND a.is_active = 1', [])
                ->join(['s' => $table('rating_store')], 's.rating_id = v.rating_id AND s.store_id = rs.store_id', [])
                ->columns(['store_id' => 'rs.store_id', 'entity_pk_value' => 'r.entity_pk_value',
                    'sum_percent' => new \Zend_Db_Expr('SUM(v.percent)'), 'vote_count' => new \Zend_Db_Expr('COUNT(*)'),
                    'min_percent' => new \Zend_Db_Expr('MIN(v.percent)'), 'max_percent' => new \Zend_Db_Expr('MAX(v.percent)')])
        );
        $countByKey = [];
        foreach ($counts as $row) {
            $countByKey[$row['store_id'] . ':' . $row['entity_pk_value']] = self::integer($row['review_count'], 2147483647);
        }
        $voteByKey = [];
        foreach ($votes as $row) {
            $voteByKey[$row['store_id'] . ':' . $row['entity_pk_value']] = $row;
        }
        $output = [];
        foreach ($productIds as $productId) {
            foreach ($storeViewCodes as $storeId => $storeViewCode) {
                $key = $storeId . ':' . $productId;
                $count = $countByKey[$key] ?? 0;
                $vote = $voteByKey[$key] ?? null;
                if ($count === 0 && $vote !== null) {
                    throw new \RuntimeException('Rating votes exist without an approved review.');
                }
                $summary = ['storeViewCode' => $storeViewCode, 'state' => 'known', 'count' => $count, 'average' => null];
                if ($count > 0) {
                    $summary = $vote === null
                        ? ['storeViewCode' => $storeViewCode, 'state' => 'unknown', 'count' => null, 'average' => null]
                        : ['storeViewCode' => $storeViewCode, 'state' => 'known', 'count' => $count, 'average' => self::average($vote)];
                }
                $output[$productId . '_' . $storeViewCode] = ['productId' => $productId, 'productReviewSummaries' => $summary];
            }
        }

        return $output;
    }

    /** The mean of the vote percents, as an exact decimal string with at most six fraction digits. */
    private static function average(array $vote): string
    {
        $count = self::integer($vote['vote_count'], 1000000000);
        $sum = self::integer($vote['sum_percent'], 100000000000);
        if ($count < 1 || self::integer($vote['min_percent'], 100) > self::integer($vote['max_percent'], 100) || $sum > $count * 100) {
            throw new \RuntimeException('Rating percentages are inconsistent.');
        }
        $units = intdiv($sum * 1000000, $count);
        $fraction = rtrim(str_pad((string)($units % 1000000), 6, '0', STR_PAD_LEFT), '0');

        return (string)intdiv($units, 1000000) . ($fraction === '' ? '' : '.' . $fraction);
    }

    private static function integer(mixed $value, int $maximum): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$value)
            || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value > $maximum) {
            throw new \RuntimeException('A review aggregate is outside its supported domain.');
        }

        return (int)$value;
    }
}
