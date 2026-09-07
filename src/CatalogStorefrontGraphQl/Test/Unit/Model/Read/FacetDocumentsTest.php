<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use PHPUnit\Framework\TestCase;

class FacetDocumentsTest extends TestCase
{
    public function testOnePrimeAnswersTheCoveredReadsAndNothingElse(): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects(self::once())->method('batch')->with('default', [
            ['entity' => 'attribute', 'any' => [
                ['options.id' => [5, 6]],
                ['filterableMode' => 2],
                ['id' => ['color'], 'frontendInput' => ['boolean', 'price']],
            ]],
            ['entity' => 'category', 'ids' => [9, 10], 'fields' => ['name', 'path']],
        ])->willReturn([
            ['color' => ['label' => 'Color']],
            ['9' => ['name' => 'Shoes', 'path' => '1/2/9'], '10' => ['name' => 'Boots', 'path' => '1/2/9/10']],
        ]);
        $facets = new FacetDocuments($storage);

        self::assertNull($facets->attributes('default', [5], ['color']));
        $facets->prime('default', [5, 6], ['color'], [9, 10]);

        self::assertSame(['color' => ['label' => 'Color']], $facets->attributes('default', [5], ['color']));
        self::assertSame(['color' => ['label' => 'Color']], $facets->attributes('default', ['6', 5], []));
        self::assertNull($facets->attributes('default', [7], ['color']));
        self::assertNull($facets->attributes('default', [5], ['size']));
        self::assertNull($facets->attributes('second', [5], ['color']));
        self::assertSame(['10' => ['name' => 'Boots', 'path' => '1/2/9/10']], $facets->categories('default', [10]));
        self::assertNull($facets->categories('default', [10, 11]));

        $facets->_resetState();
        self::assertNull($facets->categories('default', [9]));
    }
}
