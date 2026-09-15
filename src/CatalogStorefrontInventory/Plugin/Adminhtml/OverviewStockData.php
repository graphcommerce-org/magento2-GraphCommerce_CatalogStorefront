<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Plugin\Adminhtml;

use GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider\OverviewDataProvider;
use GraphCommerce\CatalogStorefrontInventory\Model\Adminhtml\StockOverview;

/** Optional enrichment of the native Admin listings; no storefront read-path changes. */
class OverviewStockData
{
    public function __construct(private readonly StockOverview $overview)
    {
    }

    public function afterGetData(OverviewDataProvider $subject, array $result): array
    {
        $name = $subject->getName();
        if (!in_array($name, ['catalog_storefront_stocks_listing_data_source', 'catalog_storefront_views_listing_data_source'], true)) {
            return $result;
        }
        $snapshot = $this->overview->get();
        if ($name === 'catalog_storefront_views_listing_data_source') {
            foreach ($result['items'] as &$view) {
                $stock = $snapshot['viewStocks'][$view['id']] ?? null;
                $view['stock'] = $stock['name'] ?? '';
                $view['stockId'] = $stock['id'] ?? null;
            }
            unset($view);
            return $result;
        }
        $counts = $subject->getStockFeedCounts();
        $items = $snapshot['stocks'];
        foreach ($items as &$stock) {
            $stock['feedStock'] = $counts[$stock['id']] ?? $counts['*'] ?? [
                'value' => 'Unknown', 'badges' => [],
                'description' => 'Stock feed receipts are unavailable.',
            ];
        }
        unset($stock);
        return array_replace($result, ['items' => $items, 'totalRecords' => count($items)]);
    }
}
