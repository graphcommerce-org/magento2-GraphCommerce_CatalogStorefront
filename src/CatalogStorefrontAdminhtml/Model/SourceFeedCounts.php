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
        $connection = $this->resource->getConnection();
        foreach ([
            ['product', 'catalog_data_exporter_products', 'feedProducts'],
            ['category', 'catalog_data_exporter_categories', 'feedCategories'],
            ['attribute', 'catalog_data_exporter_product_attributes', 'feedAttributes'],
        ] as [$entity, $indexer, $field]) {
            $feed = $feeds[$entity][$indexer] ?? null;
            if ($feed === null) {
                continue;
            }
            try {
                $table = $this->resource->getTableName($feed->getFeedTableName());
                // Only an accepted live row counts as imported. Failed/pending deletes still need attention.
                // The exporter has no worker lease state: these are pending deliveries, not active processes.
                $pending = "(status IN (-1, 102, 202) OR (status = 0 AND (errors IS NULL OR TRIM(errors) IN ('', '[]', '{}', 'null'))))";
                $scope = "JSON_UNQUOTE(JSON_EXTRACT(feed_data, '$.storeViewCode'))";
                $rows = $connection->fetchAll($connection->select()->from($table, [
                    'scope' => new \Zend_Db_Expr($scope),
                    'imported' => new \Zend_Db_Expr('SUM(status = 200 AND is_deleted = 0)'),
                    'pending' => new \Zend_Db_Expr('SUM(' . $pending . ')'),
                    'failed' => new \Zend_Db_Expr('SUM(status <> 200 AND NOT ' . $pending . ')'),
                ])->where('JSON_VALID(feed_data)')->group(new \Zend_Db_Expr($scope)));
                // An empty existing feed is known zero, while an unavailable feed stays unknown.
                $this->counts['*'][$field] = self::cell(0, 0, 0);
                foreach ($rows as $row) {
                    if (is_string($row['scope']) && $row['scope'] !== '') {
                        $this->counts[$row['scope']][$field] = self::cell((int)$row['imported'], (int)$row['pending'], (int)$row['failed']);
                    }
                }
            } catch (\Exception) {
                // Missing/unreadable optional feeds must not masquerade as a healthy empty source.
            }
        }
        return $this->counts;
    }

    public static function cell(int $imported, int $pending, int $failed): array
    {
        $badges = [];
        if ($pending > 0) {
            $badges[] = ['label' => number_format($pending) . ' PENDING', 'background' => '#FFDCC4'];
        }
        if ($failed > 0) {
            $badges[] = ['label' => number_format($failed) . ' FAILED', 'background' => '#FFD8D5'];
        }
        return ['value' => number_format($imported), 'badges' => $badges, 'hasBadge' => false,
            'description' => 'Latest accepted, non-deleted source feed records. Pending and failed deliveries are separate; pending does not mean actively processing.'];
    }
}
