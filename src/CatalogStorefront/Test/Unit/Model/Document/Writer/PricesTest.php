<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefront\Model\Document\Writer\Prices;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use PHPUnit\Framework\TestCase;

class PricesTest extends TestCase
{
    public function testMergesTheBatchWithStoredRowsAndIndexesEveryGroup(): void
    {
        $scopes = $this->scopes();

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

        $writer = new Prices($storage, $scopes, new ProductPrice());
        $writer->write([
            ['productId' => 7, 'websiteCode' => 'base', 'customerGroupCode' => sha1('1'), 'regular' => 10.0, 'discounts' => [['price' => 8.0]]],
            ['productId' => 7, 'websiteCode' => 'base', 'customerGroupCode' => sha1('99'), 'regular' => 1.0],
            ['productId' => 8, 'websiteCode' => 'other', 'customerGroupCode' => '0', 'regular' => 1.0],
        ]);
    }

    public function testADeletedRowNamesTheProductBySkuAndDropsThatGroupsStoredRow(): void
    {
        $scopes = $this->scopes();

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

        (new Prices($storage, $scopes, new ProductPrice()))->write([
            ['sku' => 'A-1', 'websiteCode' => 'base', 'customerGroupCode' => sha1('1'), 'deleted' => true, 'updatedAt' => '2026-09-07T06:19:57+00:00'],
        ]);
    }

    private function scopes(): Scopes
    {
        $scopes = $this->createMock(Scopes::class);
        $scopes->method('storeViewsOfWebsite')->willReturnCallback(
            static fn(string $websiteCode) => $websiteCode === 'base' ? ['default'] : []
        );
        $scopes->method('customerGroups')->willReturn([sha1('0') => 0, sha1('1') => 1]);

        return $scopes;
    }
}
