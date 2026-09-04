<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

/**
 * Adds to a stock status row the stock item's own minimum quantity and
 * minimum and maximum sale quantities, null where the item takes the
 * configured value; the read side resolves the configuration, so a changed
 * setting needs no export.
 */
class StockItem
{
    private const FIELDS = ['minQty' => 'min_qty', 'minSaleQty' => 'min_sale_qty', 'maxSaleQty' => 'max_sale_qty'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function get(array $values): array
    {
        $ids = array_unique(array_map(static fn(array $value) => (int)$value['productId'], $values));
        if (!$ids) {
            return [];
        }
        $columns = ['product_id'];
        foreach (self::FIELDS as $column) {
            $columns[] = $column;
            $columns[] = 'use_config_' . $column;
        }
        $connection = $this->resourceConnection->getConnection();
        $items = $connection->fetchAssoc(
            $connection->select()
                ->from($this->resourceConnection->getTableName('cataloginventory_stock_item'), $columns)
                ->where('product_id IN (?)', $ids)
        );
        $output = [];
        foreach ($values as $value) {
            $item = $items[(int)$value['productId']] ?? null;
            $row = ['productId' => $value['productId'], 'stockId' => $value['stockId']];
            foreach (self::FIELDS as $field => $column) {
                $row[$field] = $item && !$item['use_config_' . $column] ? (float)$item[$column] : null;
            }
            $output[$value['stockId'] . '_' . $value['productId']] = $row;
        }

        return $output;
    }
}
