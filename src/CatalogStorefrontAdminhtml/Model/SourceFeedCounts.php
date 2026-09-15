<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Model;

use GraphCommerce\CatalogStorefront\Model\Feeds;
use Magento\Framework\App\ResourceConnection;

/** Latest base-feed delivery receipts, not native catalog size or search eligibility. */
class SourceFeedCounts
{
    private ?array $counts = null;

    public function __construct(private readonly ResourceConnection $resource, private readonly Feeds $feeds)
    {
    }

    /** @return array<string, array<string, array>> Indexed by source store-view code and UI field. */
    public function get(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }
        $this->counts = [];
        $feeds = $this->feeds->byEntity();
        foreach ([
            ['product', 'catalog_data_exporter_products', 'feedProducts'],
            ['category', 'catalog_data_exporter_categories', 'feedCategories'],
            ['attribute', 'catalog_data_exporter_product_attributes', 'feedAttributes'],
        ] as [$entity, $indexer, $field]) {
            foreach ($this->receipts($feeds[$entity][$indexer] ?? null, 'storeViewCode') as $scope => $cell) {
                $this->counts[$scope][$field] = $cell;
            }
        }
        return $this->counts;
    }

    /** Stock delivery receipts are scoped by stockId, never by Catalog Source or website. */
    public function stocks(): array
    {
        $feeds = $this->feeds->byEntity();
        return $this->receipts($feeds['product']['inventory_data_exporter_stock_status'] ?? null, 'stockId');
    }

    /** @return array<string|int, array> Missing/unreadable feeds are unknown; existing empty feeds are zero. */
    private function receipts(?\Magento\DataExporter\Model\Indexer\FeedIndexMetadata $feed, string $scopeField): array
    {
        if ($feed === null) {
            return [];
        }
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName($feed->getFeedTableName());
            // No worker lease exists: pending does not mean actively processing. Failed deletes need attention.
            $pending = "(status IN (-1, 102, 202) OR (status = 0 AND (errors IS NULL OR TRIM(errors) IN ('', '[]', '{}', 'null'))))";
            $scope = "JSON_UNQUOTE(JSON_EXTRACT(feed_data, '$." . $scopeField . "'))";
            $rows = $connection->fetchAll($connection->select()->from($table, [
                'scope' => new \Zend_Db_Expr($scope),
                'imported' => new \Zend_Db_Expr('SUM(status = 200 AND is_deleted = 0)'),
                'pending' => new \Zend_Db_Expr('SUM(' . $pending . ')'),
                'failed' => new \Zend_Db_Expr('SUM(status <> 200 AND NOT ' . $pending . ')'),
            ])->where('JSON_VALID(feed_data)')->group(new \Zend_Db_Expr($scope)));
            $counts = ['*' => self::cell(0, 0, 0)];
            foreach ($rows as $row) {
                if (is_string($row['scope']) && $row['scope'] !== '') {
                    $counts[$row['scope']] = self::cell((int)$row['imported'], (int)$row['pending'], (int)$row['failed']);
                }
            }
            return $counts;
        } catch (\Exception) {
            return [];
        }
    }

    public static function cell(int $imported, int $pending, int $failed): array
    {
        $badges = [];
        if ($pending > 0) {
            $badges[] = ['label' => number_format($pending) . ' PENDING', 'tone' => 'pending'];
        }
        if ($failed > 0) {
            $badges[] = ['label' => number_format($failed) . ' FAILED', 'tone' => 'failed'];
        }
        return ['value' => number_format($imported), 'badges' => $badges,
            'description' => 'Latest accepted, non-deleted source feed records. Pending and failed deliveries are separate; pending does not mean actively processing.'];
    }
}
