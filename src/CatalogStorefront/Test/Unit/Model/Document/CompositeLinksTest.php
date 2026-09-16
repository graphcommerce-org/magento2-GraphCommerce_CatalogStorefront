<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document;

use GraphCommerce\CatalogStorefront\Model\Document\CompositeLinks;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use PHPUnit\Framework\TestCase;

final class CompositeLinksTest extends TestCase
{
    private const CHILD_KEYS = ['variantIds', 'groupedChildIds', 'bundleChildIds'];

    public function testEmptyGroupedParentProducesAndClearsAnExplicitChildRoster(): void
    {
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->expects(self::never())->method('storedBySku');
        $storage->expects(self::once())
            ->method('stored')
            ->with('default', [62], self::CHILD_KEYS)
            ->willReturn([62 => ['groupedChildIds' => [15]]]);

        [$upserts, $changes] = (new CompositeLinks($storage))->upserts('default', [
            62 => [
                'productId' => 62,
                'sku' => 'empty-grouped',
                'type' => 'grouped',
                // DataExporter encodes an empty repeated options record as null.
                'optionsV2' => null,
                'parents' => null,
            ],
        ], []);

        self::assertSame([
            62 => [
                'parentIds' => [],
                'groupedParentIds' => [],
                'bundleParentIds' => [],
                'variantIds' => [],
                'groupedChildIds' => [],
                'bundleChildIds' => [],
            ],
        ], $upserts);
        self::assertSame([
            15 => ['groupedParentIds' => ['remove' => [62]]],
        ], $changes);
    }

    public function testConfigurableParentAndChildInOneBatchLinkBothSides(): void
    {
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->expects(self::once())
            ->method('storedBySku')
            ->with('default', ['red-s', 'shirt'])
            ->willReturn([]);
        $storage->expects(self::once())
            ->method('stored')
            ->with('default', [62], self::CHILD_KEYS)
            ->willReturn([62 => ['variantIds' => [47, 99]]]);

        [$upserts, $changes] = (new CompositeLinks($storage))->upserts('default', [
            62 => [
                'productId' => 62,
                'sku' => 'shirt',
                'type' => 'configurable',
                'variants' => [['sku' => 'red-s']],
            ],
            47 => [
                'productId' => 47,
                'sku' => 'red-s',
                'type' => 'simple',
                'parents' => [['sku' => 'shirt', 'productType' => 'configurable']],
            ],
        ], []);

        self::assertSame([62, 47], array_keys($upserts));
        self::assertSame([62], $upserts[47]['parentIds']);
        self::assertSame([47], $upserts[62]['variantIds']);
        // The child the parent no longer lists loses the link in the store.
        self::assertSame([99 => ['parentIds' => ['remove' => [62]]]], $changes);
    }
}
