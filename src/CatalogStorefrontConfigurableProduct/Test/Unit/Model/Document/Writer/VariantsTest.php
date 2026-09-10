<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\StoreAssignments;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Document\Writer\Variants;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class VariantsTest extends TestCase
{
    public function testRetainedDeletionIdentityClearsBothSidesOfTheRelation(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([$store]);
        $assignments = $this->createMock(StoreAssignments::class);
        $assignments->method('storesOf')->with([47, 62])->willReturn([
            47 => ['default'],
            62 => ['default'],
        ]);
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->expects(self::once())->method('stored')
            ->with('default', [47], ['parentIds'])
            ->willReturn([47 => ['parentIds' => [62, 70]]]);
        $storage->expects(self::once())->method('upsert')->with('default', [
            62 => ['variantIds' => ['v47' => null]],
            47 => ['parentIds' => [70]],
        ]);

        (new Variants($storage, $stores, $assignments))->write([[
            'productId' => 47,
            'parentId' => 62,
            'productSku' => 'MH01-XS-Black',
            'parentSku' => 'MH01',
            'deleted' => true,
        ]]);
    }

    public function testHistoricalDeletionWithoutRetainedParentRefusesDelivery(): void
    {
        $writer = new Variants(
            $this->createMock(ProductDocumentStorageInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StoreAssignments::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deleted variants feed row has no productId or parentId');

        $writer->write([[
            'productId' => 47,
            'productSku' => 'MH01-XS-Black',
            'parentSku' => 'MH01',
            'deleted' => true,
        ]]);
    }
}
