<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Test\Unit\Plugin\Sales;

use GraphCommerce\CatalogStorefrontInventory\Plugin\Sales\RecheckSalabilityAfterCancel;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventoryIndexer\Model\Queue\GetSalabilityDataForUpdate;
use Magento\InventoryIndexer\Model\Queue\ReservationData;
use Magento\InventorySalesApi\Model\GetAssignedStockIdForWebsiteInterface;
use Magento\InventorySalesApi\Model\GetSkuFromOrderItemInterface;
use Magento\InventorySalesApi\Model\GetStockItemsDataInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A cancel publishes the salability update again after its commit when the stored salability is stale.
 */
class RecheckSalabilityAfterCancelTest extends TestCase
{
    private GetSalabilityDataForUpdate&MockObject $salabilityChanges;
    private GetStockItemsDataInterface&MockObject $stockItemsData;
    private PublisherInterface&MockObject $publisher;
    private LoggerInterface&MockObject $logger;
    private OrderRepositoryInterface&MockObject $orders;

    protected function setUp(): void
    {
        $this->salabilityChanges = $this->createMock(GetSalabilityDataForUpdate::class);
        $this->stockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->orders = $this->createMock(OrderRepositoryInterface::class);

        // A configurable parent with its simple child, and a simple product.
        $order = $this->createStub(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->item('configurable', 'WT09'),
            $this->item('simple', 'WT09-XS-Purple'),
            $this->item('simple', '24-MB02'),
        ]);
        $this->orders->method('get')->willReturnMap([[42, $order]]);
    }

    private function item(string $type, string $sku): OrderItemInterface
    {
        $item = $this->createStub(OrderItemInterface::class);
        $item->method('getProductType')->willReturn($type);
        $item->method('getSku')->willReturn($sku);

        return $item;
    }

    private function plugin(): RecheckSalabilityAfterCancel
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);

        $managed = $this->createStub(IsSourceItemManagementAllowedForProductTypeInterface::class);
        $managed->method('execute')->willReturnCallback(static fn(string $type): bool => $type === 'simple');
        $skuFromOrderItem = $this->createStub(GetSkuFromOrderItemInterface::class);
        $skuFromOrderItem->method('execute')->willReturnCallback(static fn(OrderItemInterface $item): string => $item->getSku());
        $stockIdForWebsite = $this->createStub(GetAssignedStockIdForWebsiteInterface::class);
        $stockIdForWebsite->method('execute')->willReturnMap([['base', 1]]);

        return new RecheckSalabilityAfterCancel(
            $this->orders,
            $storeManager,
            $managed,
            $skuFromOrderItem,
            $stockIdForWebsite,
            $this->salabilityChanges,
            $this->stockItemsData,
            $this->publisher,
            $this->logger
        );
    }

    /**
     * @param array<string, int|string> $statuses the stored status per sku, as the column holds it
     */
    private function stored(array $statuses): void
    {
        $this->stockItemsData->method('execute')->willReturnCallback(
            static fn(array $skus): array => array_map(
                static fn(int|string $status): array => ['quantity' => 2.0, 'is_salable' => $status],
                array_intersect_key($statuses, array_flip($skus))
            )
        );
    }

    public function testAStaleSalabilityIsPublishedAgainForTheSkusThatDiffer(): void
    {
        // The order's source-managed skus are checked; the configurable parent is MSI's to derive.
        $this->salabilityChanges->expects($this->once())->method('execute')
            ->with($this->callback(static fn(ReservationData $data): bool
                => $data->getSkus() === ['WT09-XS-Purple', '24-MB02'] && $data->getStock() === 1))
            ->willReturn(['24-MB02' => true]);
        $this->stored(['24-MB02' => '0']);
        $this->publisher->expects($this->once())->method('publish')
            ->with(
                'inventory.reservations.updateSalabilityStatus',
                $this->callback(static fn(ReservationData $data): bool => $data->getSkus() === ['24-MB02'] && $data->getStock() === 1)
            );

        $this->assertTrue($this->plugin()->afterCancel($this->createStub(OrderManagementInterface::class), true, 42));
    }

    public function testACurrentSalabilityPublishesNothing(): void
    {
        // The consumer applied the cancel already, or the cancel changed no salability.
        $this->salabilityChanges->method('execute')->willReturn([]);
        $this->publisher->expects($this->never())->method('publish');

        $this->assertTrue($this->plugin()->afterCancel($this->createStub(OrderManagementInterface::class), true, 42));
    }

    public function testAStoredStatusThatAgreesAsABooleanPublishesNothing(): void
    {
        // MSI compares the default stock's raw column value strictly with a boolean and reports every sku.
        $this->salabilityChanges->method('execute')->willReturn(['WT09-XS-Purple' => true, '24-MB02' => false]);
        $this->stored(['WT09-XS-Purple' => 1, '24-MB02' => '0']);
        $this->publisher->expects($this->never())->method('publish');

        $this->assertTrue($this->plugin()->afterCancel($this->createStub(OrderManagementInterface::class), true, 42));
    }

    public function testAnOrderThatWasNotCancelledIsNotChecked(): void
    {
        $this->orders->expects($this->never())->method('get');
        $this->publisher->expects($this->never())->method('publish');

        $this->assertFalse($this->plugin()->afterCancel($this->createStub(OrderManagementInterface::class), false, 42));
    }

    public function testAFailureIsLoggedAndTheCancelStands(): void
    {
        // The cancel is committed, so the check must not turn it into an error.
        $this->salabilityChanges->method('execute')->willThrowException(new \RuntimeException('index unavailable'));
        $this->logger->expects($this->once())->method('error')
            ->with($this->stringContains('cancelled order 42 was not checked again'));

        $this->assertTrue($this->plugin()->afterCancel($this->createStub(OrderManagementInterface::class), true, 42));
    }
}
