<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document;

use GraphCommerce\CatalogStorefront\Model\Document\CompositeLinks;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use PHPUnit\Framework\TestCase;

final class CompositeLinksTest extends TestCase
{
    public function testEmptyGroupedParentProducesAndClearsAnExplicitChildRoster(): void
    {
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->expects(self::never())->method('storedBySku');
        $storage->expects(self::once())
            ->method('stored')
            ->with('default', [62], ['groupedChildIds', 'bundleChildIds'])
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
                'groupedParentIds' => [],
                'bundleParentIds' => [],
                'groupedChildIds' => [],
                'bundleChildIds' => [],
            ],
        ], $upserts);
        self::assertSame([
            15 => ['groupedParentIds' => ['remove' => [62]]],
        ], $changes);
    }
}
