<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store views a product is assigned to, through its websites. A slice
 * writer whose feed rows name no website (stock rows belong to a stock,
 * variant rows to a relation) fans a row out to these store views only, so
 * no document exists for a product outside its websites.
 */
class StoreAssignments
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @param int[] $productIds
     * @return array<int, string[]> store view codes by product id
     */
    public function storesOf(array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $storesByWebsite = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storesByWebsite[(int)$store->getWebsiteId()][] = $store->getCode();
        }
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll($connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_website'), ['product_id', 'website_id'])
            ->where('product_id IN (?)', array_values(array_unique($productIds))));
        $stores = [];
        foreach ($rows as $row) {
            foreach ($storesByWebsite[(int)$row['website_id']] ?? [] as $store) {
                $stores[(int)$row['product_id']][] = $store;
            }
        }

        return $stores;
    }
}
