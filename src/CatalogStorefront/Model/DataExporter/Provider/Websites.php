<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

/**
 * Adds every website a product is assigned to as `websiteIds`; the row names
 * only the website of its own store view.
 */
class Websites
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
        $websites = [];
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_website'), ['product_id', 'website_id'])
            ->where('product_id IN (?)', $ids)
            ->order('website_id');
        foreach ($connection->fetchAll($select) as $row) {
            $websites[(int)$row['product_id']][] = (int)$row['website_id'];
        }
        $output = [];
        foreach ($values as $value) {
            foreach ($websites[(int)$value['productId']] ?? [] as $websiteId) {
                $output[$value['storeViewCode'] . '_' . $value['productId'] . '_' . $websiteId] = [
                    'productId' => $value['productId'],
                    'storeViewCode' => $value['storeViewCode'],
                    'websiteIds' => $websiteId,
                ];
            }
        }

        return $output;
    }
}
