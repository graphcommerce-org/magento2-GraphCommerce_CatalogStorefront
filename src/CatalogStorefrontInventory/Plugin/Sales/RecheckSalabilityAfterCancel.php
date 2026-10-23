<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Plugin\Sales;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventoryIndexer\Model\Queue\GetSalabilityDataForUpdate;
use Magento\InventoryIndexer\Model\Queue\ReservationData;
use Magento\InventorySalesApi\Model\GetAssignedStockIdForWebsiteInterface;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventorySalesApi\Model\GetStockItemsDataInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Checks the salability of a cancelled order's products again after the cancel is saved.
 *
 * MSI publishes the salability update of a cancel while the order's transaction (OrderMutex) is
 * open. A running consumer reads the reservations before the commit, finds no change, and a
 * product that the order sold out stays out of stock. After the commit, this plugin compares the
 * stored salability with the reservations and publishes the update again for the skus that
 * differ. When the consumer was faster, or the cancel changes no salability, it publishes nothing.
 *
 * MSI's GetSalabilityDataForUpdate compares the stored status of the default stock, a raw column
 * value, strictly with a boolean, so it reports every sku of that stock as changed. Its result is
 * compared again with the stored status as a boolean.
 */
class RecheckSalabilityAfterCancel
{
    private const SALABILITY_TOPIC = 'inventory.reservations.updateSalabilityStatus';

    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly StoreManagerInterface $storeManager,
        private readonly IsSourceItemManagementAllowedForProductTypeInterface $sourceItemManagementAllowed,
        private readonly GetSkuFromOrderItemInterface $skuFromOrderItem,
        private readonly GetAssignedStockIdForWebsiteInterface $stockIdForWebsite,
        private readonly GetSalabilityDataForUpdate $salabilityChanges,
        private readonly GetStockItemsDataInterface $stockItemsData,
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param OrderManagementInterface $subject
     * @param bool $result whether the order was cancelled
     * @param int|string $id the order id
     * @return bool
     */
    public function afterCancel(OrderManagementInterface $subject, $result, $id)
    {
        if (!$result) {
            return $result;
        }
        try {
            $order = $this->orders->get((int)$id);
            $skus = [];
            foreach ($order->getItems() as $item) {
                if ($this->sourceItemManagementAllowed->execute((string)$item->getProductType())) {
                    $skus[] = $this->skuFromOrderItem->execute($item);
                }
            }
            if ($skus === []) {
                return $result;
            }
            $websiteId = (int)$this->storeManager->getStore($order->getStoreId())->getWebsiteId();
            $stockId = $this->stockIdForWebsite->execute($this->storeManager->getWebsite($websiteId)->getCode());
            if ($stockId === null) {
                return $result;
            }
            $changes = $this->salabilityChanges->execute(new ReservationData(array_values(array_unique($skus)), $stockId));
            $stored = $changes === [] ? [] : (array)$this->stockItemsData->execute(array_map('strval', array_keys($changes)), $stockId);
            $changes = array_filter(
                $changes,
                static fn(bool $salable, int|string $sku): bool => !isset($stored[$sku])
                    || (bool)$stored[$sku][GetStockItemsDataInterface::IS_SALABLE] !== $salable,
                ARRAY_FILTER_USE_BOTH
            );
            if ($changes !== []) {
                $this->publisher->publish(
                    self::SALABILITY_TOPIC,
                    new ReservationData(array_map('strval', array_keys($changes)), $stockId)
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf(
                    'The salability of the products of cancelled order %s was not checked again, so they keep the stock status MSI computed before the cancel was saved: %s',
                    $id,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }

        return $result;
    }
}
