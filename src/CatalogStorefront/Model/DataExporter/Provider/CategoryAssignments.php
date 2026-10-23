<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

/**
 * Adds every category a product is assigned to as `categoryAssignmentIds`, the rows of
 * catalog_category_product that core's `getCategoryIds()` reads. The feed's `categoryData` comes
 * from the category product index, which adds the anchor ancestors and leaves out the root, so a
 * model built from it finds a category core's model does not.
 */
class CategoryAssignments
{
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
        $connection = $this->resourceConnection->getConnection();
        $categories = [];
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_category_product'), ['product_id', 'category_id'])
            ->where('product_id IN (?)', $ids)
            ->order('category_id');
        foreach ($connection->fetchAll($select) as $row) {
            $categories[(int)$row['product_id']][] = (int)$row['category_id'];
        }

        $output = [];
        foreach ($values as $value) {
            foreach ($categories[(int)$value['productId']] ?? [] as $categoryId) {
                $output[$value['storeViewCode'] . '_' . $value['productId'] . '_' . $categoryId] = [
                    'productId' => $value['productId'],
                    'storeViewCode' => $value['storeViewCode'],
                    'categoryAssignmentIds' => $categoryId,
                ];
            }
        }

        return $output;
    }
}
