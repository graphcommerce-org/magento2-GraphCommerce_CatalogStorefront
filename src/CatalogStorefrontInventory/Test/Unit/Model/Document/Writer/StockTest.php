<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\StoreAssignments;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontInventory\Model\Document\Writer\Stock;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\GetStockBySalesChannelInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

class StockTest extends TestCase
{
    public function testADeletedRowNamesTheProductBySkuAndEmptiesItsStockSlice(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $website = $this->createMock(Website::class);
        $website->method('getCode')->willReturn('base');
        $website->method('getStores')->willReturn([$store]);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);
        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $getStock = $this->createMock(GetStockBySalesChannelInterface::class);
        $getStock->method('execute')->willReturn($stock);
        $channels = $this->createMock(SalesChannelInterfaceFactory::class);
        $channels->method('create')->willReturn($this->createMock(SalesChannelInterface::class));
        $assignments = $this->createMock(StoreAssignments::class);
        $assignments->method('storesOf')->with([5])->willReturn([5 => ['default']]);

        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->method('storedBySku')->with('default', ['24-MB01'])->willReturn([3 => ['sku' => '24-MB01']]);
        $live = ['productId' => 5, 'stockId' => 1, 'sku' => '24-MB02', 'qty' => 100, 'isSalable' => true];
        $storage->expects(self::once())->method('upsert')->with('default', [
            3 => ['stock' => []],
            5 => ['stock' => $live],
        ]);

        $writer = new Stock($storage, $storeManager, $getStock, $channels, $assignments);
        $writer->write([
            ['sku' => '24-MB01', 'stockId' => 1, 'deleted' => true, 'updatedAt' => '2026-09-15T00:00:00+00:00'],
            $live,
        ]);
    }
}
