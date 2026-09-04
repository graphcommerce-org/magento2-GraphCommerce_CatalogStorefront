<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adds to a categories feed row the number of products the category lists in
 * the store view, as the product count resolver counts them: the products of
 * the category product index visible in the site, assigned to the website,
 * and in stock unless out-of-stock products are shown.
 */
class CategoryProductCount
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly TableMaintainer $tableMaintainer,
        private readonly Visibility $visibility,
        private readonly StockConfigurationInterface $stockConfiguration,
    ) {
    }

    public function get(array $values): array
    {
        $idsByStore = [];
        foreach ($values as $value) {
            $idsByStore[$value['storeViewCode']][] = (int)$value['categoryId'];
        }
        $connection = $this->resourceConnection->getConnection();
        $output = [];
        foreach ($idsByStore as $storeViewCode => $ids) {
            $store = $this->storeManager->getStore($storeViewCode);
            $storeId = (int)$store->getId();
            $websiteId = (int)$store->getWebsiteId();
            $select = $connection->select()
                ->from(['cat_index' => $this->tableMaintainer->getMainTable($storeId)], ['category_id', 'count' => 'COUNT(DISTINCT cat_index.product_id)'])
                ->join(
                    ['product_website' => $this->resourceConnection->getTableName('catalog_product_website')],
                    $connection->quoteInto('product_website.product_id = cat_index.product_id AND product_website.website_id = ?', $websiteId),
                    []
                )
                ->where('cat_index.category_id IN (?)', array_unique($ids))
                ->where('cat_index.visibility IN (?)', $this->visibility->getVisibleInSiteIds())
                ->group('cat_index.category_id');
            if (!$this->stockConfiguration->isShowOutOfStock($storeId)) {
                $select->join(
                    ['stock_status' => $this->resourceConnection->getTableName('cataloginventory_stock_status')],
                    'stock_status.product_id = cat_index.product_id AND stock_status.website_id = 0 AND stock_status.stock_status = 1',
                    []
                );
            }
            $counts = $connection->fetchPairs($select);
            foreach ($ids as $id) {
                $output[$storeViewCode . '_' . $id] = [
                    'categoryId' => (string)$id,
                    'storeViewCode' => $storeViewCode,
                    'productCount' => (int)($counts[$id] ?? 0),
                ];
            }
        }

        return $output;
    }
}
