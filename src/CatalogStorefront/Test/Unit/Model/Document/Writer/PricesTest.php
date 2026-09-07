<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Writer\Prices;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class PricesTest extends TestCase
{
    public function testMergesTheBatchWithStoredRowsAndIndexesEveryGroup(): void
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $store = $this->createMock(Store::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getWebsite')->willReturn($website);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $group = $this->createMock(GroupInterface::class);
        $group->method('getId')->willReturn(1);
        $groupManagement = $this->createMock(GroupManagementInterface::class);
        $groupManagement->method('getLoggedInGroups')->willReturn([$group]);

        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->method('stored')->with('default', [7], ['prices'])->willReturn([
            7 => ['prices' => [['group' => 'all', 'regular' => 10.0, 'discounts' => []]]],
        ]);
        $storage->expects(self::once())->method('upsert')->with('default', [
            7 => [
                'prices' => [
                    ['group' => '1', 'productId' => 7, 'websiteCode' => 'base', 'regular' => 10.0, 'discounts' => [['price' => 8.0]]],
                    ['group' => 'all', 'regular' => 10.0, 'discounts' => []],
                ],
                'priceIndex' => [
                    ['group' => '0', 'regular' => 10.0, 'final' => 10.0, 'precision' => 2],
                    ['group' => '1', 'regular' => 10.0, 'final' => 8.0, 'precision' => 2],
                ],
            ],
        ]);

        $writer = new Prices($storage, $storeManager, $groupManagement, new ProductPrice());
        $writer->write([
            ['productId' => 7, 'websiteCode' => 'base', 'customerGroupCode' => sha1('1'), 'regular' => 10.0, 'discounts' => [['price' => 8.0]]],
            ['productId' => 7, 'websiteCode' => 'base', 'customerGroupCode' => sha1('99'), 'regular' => 1.0],
            ['productId' => 8, 'websiteCode' => 'other', 'customerGroupCode' => '0', 'regular' => 1.0],
        ]);
    }

    public function testADeletedRowNamesTheProductBySkuAndDropsThatGroupsStoredRow(): void
    {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $store = $this->createMock(Store::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getWebsite')->willReturn($website);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $group = $this->createMock(GroupInterface::class);
        $group->method('getId')->willReturn(1);
        $groupManagement = $this->createMock(GroupManagementInterface::class);
        $groupManagement->method('getLoggedInGroups')->willReturn([$group]);

        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->method('storedBySku')->with('default', ['A-1'])->willReturn([7 => ['sku' => 'A-1']]);
        $storage->method('stored')->with('default', [7], ['prices'])->willReturn([
            7 => ['prices' => [
                ['group' => 'all', 'regular' => 10.0, 'discounts' => []],
                ['group' => '1', 'regular' => 10.0, 'discounts' => [['price' => 8.0, 'code' => 'catalog_rule']]],
            ]],
        ]);
        $storage->expects(self::once())->method('upsert')->with('default', [
            7 => [
                'prices' => [['group' => 'all', 'regular' => 10.0, 'discounts' => []]],
                'priceIndex' => [
                    ['group' => '0', 'regular' => 10.0, 'final' => 10.0, 'precision' => 2],
                    ['group' => '1', 'regular' => 10.0, 'final' => 10.0, 'precision' => 2],
                ],
            ],
        ]);

        (new Prices($storage, $storeManager, $groupManagement, new ProductPrice()))->write([
            ['sku' => 'A-1', 'websiteCode' => 'base', 'customerGroupCode' => sha1('1'), 'deleted' => true, 'updatedAt' => '2026-09-07T06:19:57+00:00'],
        ]);
    }
}
