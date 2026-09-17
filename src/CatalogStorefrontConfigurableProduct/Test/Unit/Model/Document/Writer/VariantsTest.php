<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Document\Writer\Variants;
use PHPUnit\Framework\TestCase;

class VariantsTest extends TestCase
{
    public function testRetainedDeletionIdentityClearsBothSidesOfTheRelation(): void
    {
        $scopes = $this->createMock(Scopes::class);
        $scopes->method('storeViews')->willReturn(['default']);
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->expects(self::once())->method('stored')
            ->with('default', [47, 62], ['parentIds'])
            ->willReturn([47 => ['parentIds' => [62, 70]], 62 => []]);
        $storage->expects(self::once())->method('upsert')->with('default', [
            62 => ['variantIds' => ['v47' => null]],
            47 => ['parentIds' => [70]],
        ]);

        (new Variants($storage, $scopes))->write([[
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
            $this->createMock(Scopes::class),
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
