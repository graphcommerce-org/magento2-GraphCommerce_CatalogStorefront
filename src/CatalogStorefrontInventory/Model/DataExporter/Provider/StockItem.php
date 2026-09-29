<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

class StockItem
{
    private const FIELDS = [
        'minQty' => 'min_qty',
        'minSaleQty' => 'min_sale_qty',
        'maxSaleQty' => 'max_sale_qty',
        'qtyIncrements' => 'qty_increments',
        'enableQtyIncrements' => 'enable_qty_increments',
        'manageStock' => 'manage_stock',
        'backorders' => 'backorders',
    ];

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
        $columns = ['product_id', 'qty', 'is_in_stock', 'is_qty_decimal'];
        foreach (self::FIELDS as $column) {
            $columns[] = $column;
            $columns[] = 'use_config_' . ($column === 'enable_qty_increments' ? 'enable_qty_inc' : $column);
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
            $row['itemQty'] = (float)($item['qty'] ?? 0);
            $row['itemInStock'] = (bool)($item['is_in_stock'] ?? false);
            $row['isQtyDecimal'] = (bool)($item['is_qty_decimal'] ?? false);
            foreach (self::FIELDS as $field => $column) {
                $config = 'use_config_' . ($column === 'enable_qty_increments' ? 'enable_qty_inc' : $column);
                $row[$field] = $item && !$item[$config] ? (float)$item[$column] : null;
            }
            $output[$value['stockId'] . '_' . $value['productId']] = $row;
        }

        return $output;
    }
}
