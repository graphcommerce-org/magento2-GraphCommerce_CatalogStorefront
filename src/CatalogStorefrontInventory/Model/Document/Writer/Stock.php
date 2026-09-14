<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\StoreAssignments;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\GetStockBySalesChannelInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The inventory stock status feed is the stock slice of the product document.
 * A row belongs to one stock, and a stock sells through websites, so the row
 * lands on the documents of the store views of those websites the product is
 * assigned to. A stock without a website leaves no trace; a website without
 * a stock gets no slice.
 */
class Stock implements FeedWriterInterface
{
    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly StoreManagerInterface $storeManager,
        private readonly GetStockBySalesChannelInterface $getStockBySalesChannel,
        private readonly SalesChannelInterfaceFactory $salesChannelFactory,
        private readonly StoreAssignments $assignments,
    ) {
    }

    public function write(array $rows): void
    {
        $storesByStock = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $channel = $this->salesChannelFactory->create();
            $channel->setType(SalesChannelInterface::TYPE_WEBSITE);
            $channel->setCode($website->getCode());
            try {
                $stockId = (int)$this->getStockBySalesChannel->execute($channel)->getStockId();
            } catch (NoSuchEntityException) {
                continue;
            }
            foreach ($website->getStores() as $store) {
                $storesByStock[$stockId][] = $store->getCode();
            }
        }

        // A deleted row (the product left the stock's websites) names the product by sku only;
        // an empty slice reads as no slice, so the products feed's inStock answers again.
        $dropsByStore = [];
        $live = [];
        foreach ($rows as $row) {
            if (empty($row['deleted'])) {
                $live[] = $row;
                continue;
            }
            $stores = isset($row['stockId']) ? $storesByStock[(int)$row['stockId']] ?? [] : array_merge([], ...array_values($storesByStock));
            foreach ($stores as $store) {
                $dropsByStore[$store][] = (string)$row['sku'];
            }
        }
        $upserts = [];
        foreach ($dropsByStore as $store => $skus) {
            foreach ($this->storage->storedBySku($store, array_values(array_unique($skus))) as $id => $document) {
                $upserts[$store][$id]['stock'] = [];
            }
        }
        $assigned = $this->assignments->storesOf(array_map(static fn(array $row) => (int)$row['productId'], $live));
        foreach ($live as $row) {
            $id = (int)$row['productId'];
            foreach (array_intersect($storesByStock[(int)($row['stockId'] ?? 0)] ?? [], $assigned[$id] ?? []) as $store) {
                $upserts[$store][$id]['stock'] = $row;
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert($store, $documents);
        }
    }
}
