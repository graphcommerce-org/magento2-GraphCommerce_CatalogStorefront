<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventoryGraphQl\Model\Prefill;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Config\Source\NotAvailableMessage;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;

/**
 * The stock fields from the stock slice: stock_status, only_x_left_in_stock
 * (the salable quantity less the stock item's minimum, when positive and at
 * most the configured threshold; only product types with their own source
 * items have one), quantity (null when the not-available message hides it),
 * min_sale_qty and max_sale_qty (the slice carries the item's own values,
 * null where it takes the configured one).
 */
class Stock implements PrefillerInterface
{
    private const CONFIG_NOT_AVAILABLE_MESSAGE = 'cataloginventory/options/not_available_message';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly IsSourceItemManagementAllowedForProductTypeInterface $sourceItemsAllowed,
    ) {
    }

    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('stock_status', 'only_x_left_in_stock', 'quantity', 'min_sale_qty', 'max_sale_qty')) {
            return [];
        }
        $storeId = (int)$request->store->getId();
        $quantityShown = (int)$this->scopeConfig->getValue(self::CONFIG_NOT_AVAILABLE_MESSAGE)
            !== NotAvailableMessage::VALUE_NOT_ENOUGH_ITEMS;
        $output = [];
        foreach ($models as $id => $product) {
            $document = $documents[$id] ?? [];
            $stock = $document['stock'] ?? null;
            $salable = (bool)($stock['isSalable'] ?? $document['inStock'] ?? false);
            $filled = [];
            if ($request->selects('stock_status')) {
                $filled['stock_status'] = $salable ? 'IN_STOCK' : 'OUT_OF_STOCK';
            }
            if ($request->selects('only_x_left_in_stock')) {
                $filled['only_x_left_in_stock'] = null;
                if (!empty($stock['isSalable']) && $this->sourceItemsAllowed->execute($product->getTypeId())) {
                    $left = (float)($stock['qtyForSale'] ?? 0)
                        - (float)($stock['minQty'] ?? $this->stockConfiguration->getMinQty($storeId));
                    $filled['only_x_left_in_stock'] = $left > 0
                        && $left <= (float)$this->stockConfiguration->getStockThresholdQty($storeId)
                        ? $left
                        : null;
                }
            }
            if ($request->selects('quantity')) {
                $filled['quantity'] = $quantityShown ? (float)($stock['qty'] ?? 0) : null;
            }
            if ($request->selects('min_sale_qty')) {
                $filled['min_sale_qty'] = (float)($stock['minSaleQty'] ?? $this->stockConfiguration->getMinSaleQty($storeId));
            }
            if ($request->selects('max_sale_qty')) {
                $filled['max_sale_qty'] = (float)($stock['maxSaleQty'] ?? $this->stockConfiguration->getMaxSaleQty($storeId));
            }
            $output[$id] = $filled;
        }

        return $output;
    }
}
