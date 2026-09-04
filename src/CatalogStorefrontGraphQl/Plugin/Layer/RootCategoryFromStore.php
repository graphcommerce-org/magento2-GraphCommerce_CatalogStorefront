<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Layer;

use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\RootCategoryProvider;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store's root category id is on the loaded store model; core's provider
 * queries the store tables for it on every facet build.
 */
class RootCategoryFromStore
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function aroundGetRootCategory(RootCategoryProvider $subject, \Closure $proceed, int $storeId): int
    {
        return (int)$this->storeManager->getStore($storeId)->getRootCategoryId();
    }
}
