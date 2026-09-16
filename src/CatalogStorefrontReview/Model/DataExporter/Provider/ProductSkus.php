<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

/** Adds the SKU of its product to a review row, so a reader joins reviews on the product identity. */
class ProductSkus
{
    private const MAX_PRODUCTS = 10000;

    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
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
            throw new \RuntimeException('A review batch names more products than the SKU provider supports.');
        }
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id', 'sku'])
                ->where('entity_id IN (?)', $productIds)
        );
        $output = [];
        foreach ($rows as $productId => $sku) {
            $output[(int)$productId] = ['productId' => (int)$productId, 'sku' => (string)$sku];
        }

        return $output;
    }
}
