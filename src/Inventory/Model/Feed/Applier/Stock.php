<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model\Feed\Applier;

use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use GraphCommerce\CatalogStorefrontApi\Feed\FeedApplierInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The inventory stock status feed is the stock slice of the product document,
 * written to every store view regardless of stock id.
 */
class Stock implements FeedApplierInterface
{
    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function apply(array $rows): void
    {
        $upserts = [];
        $stores = array_map(static fn($store) => $store->getCode(), $this->storeManager->getStores());
        foreach ($rows as $row) {
            foreach ($stores as $store) {
                $upserts[$store][(int)$row['productId']]['stock'] = $row;
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert($store, $documents);
        }
    }
}
