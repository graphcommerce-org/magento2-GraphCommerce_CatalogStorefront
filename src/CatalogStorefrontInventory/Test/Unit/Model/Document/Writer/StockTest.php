<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontInventory\Model\Document\Writer\Stock;
use PHPUnit\Framework\TestCase;

class StockTest extends TestCase
{
    public function testADeletedRowNamesTheProductBySkuAndEmptiesItsStockSlice(): void
    {
        $scopes = $this->createMock(Scopes::class);
        $scopes->method('storeViewsOfWebsite')->willReturnCallback(
            static fn(string $websiteCode) => $websiteCode === 'base' ? ['default'] : []
        );

        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->method('storedBySku')->willReturn([3 => ['sku' => '24-MB01']]);
        $storage->method('stored')->willReturn([5 => ['sku' => '24-MB02']]);
        $slice = ['productId' => 5, 'stockId' => 1, 'sku' => '24-MB02', 'qty' => 100, 'isSalable' => true];
        $storage->expects(self::once())->method('upsert')->with('default', [
            3 => ['stock' => []],
            5 => ['stock' => $slice],
        ]);

        (new Stock($storage, $scopes))->write([
            ['sku' => '24-MB01', 'stockId' => 1, 'websiteCodes' => ['base'], 'deleted' => true, 'updatedAt' => '2026-09-15T00:00:00+00:00'],
            $slice + ['websiteCodes' => ['base']],
        ]);
    }

    public function testARowOfAnotherWebsiteAndAProductWithoutADocumentGetNoSlice(): void
    {
        $scopes = $this->createMock(Scopes::class);
        $scopes->method('storeViewsOfWebsite')->willReturnCallback(
            static fn(string $websiteCode) => $websiteCode === 'base' ? ['default'] : []
        );
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->method('stored')->willReturn([]);
        $storage->expects(self::never())->method('upsert');

        (new Stock($storage, $scopes))->write([
            ['productId' => 5, 'stockId' => 1, 'sku' => '24-MB02', 'websiteCodes' => ['base']],
            ['productId' => 6, 'stockId' => 2, 'sku' => '24-MB03', 'websiteCodes' => ['other']],
        ]);
    }
}
