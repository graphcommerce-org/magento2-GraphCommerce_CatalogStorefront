<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\CompositeLinks;
use GraphCommerce\CatalogStorefront\Model\Document\Writer\Products;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * The document contract of the products feed: a batch in, per store view the
 * documents out, with the feed slices of the other feeds left out, every slice
 * a reader takes in the shape the reader takes it, the composite links merged
 * and the deletions routed.
 */
class ProductsTest extends TestCase
{
    /** @var array<string, array<int, array>> the documents per store view */
    private array $upserts = [];

    /** @var array<string, array> */
    private array $listChanges = [];

    /** @var array<string, int[]> */
    private array $deletes = [];

    private function write(array $batch, ?\Closure $links = null): void
    {
        $compositeLinks = $this->createMock(CompositeLinks::class);
        $compositeLinks->method('upserts')->willReturnCallback(
            $links ?? static fn(string $store, array $documents, array $deleted): array => [[], []]
        );
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->method('upsert')->willReturnCallback(function (string $store, array $documents): void {
            $this->upserts[$store] = $documents;
        });
        $storage->method('updateLists')->willReturnCallback(function (string $store, array $changes): void {
            $this->listChanges[$store] = $changes;
        });
        $storage->method('delete')->willReturnCallback(function (string $store, array $ids): void {
            $this->deletes[$store] = $ids;
        });

        (new Products($storage, $compositeLinks))->write($batch);
    }

    public function testWritesTheBatchAsDocumentsPerStoreView(): void
    {
        $batch = [
            ['productId' => 7, 'storeViewCode' => 'default', 'sku' => 'A', 'type' => 'simple', 'prices' => null, 'stock' => null, 'name' => 'A'],
            ['productId' => 8, 'storeViewCode' => 'default', 'sku' => 'B', 'type' => 'simple', 'deleted' => true],
            ['productId' => 7, 'storeViewCode' => 'second', 'sku' => 'A', 'type' => 'simple', 'name' => 'A second'],
        ];

        $this->write($batch, static fn(string $store, array $documents, array $deleted): array => $store === 'default'
            ? [[7 => ['parentIds' => [70]]], ['added' => [7]]]
            : [[], []]);

        $this->assertSame([
            'default' => [7 => ['productId' => 7, 'storeViewCode' => 'default', 'sku' => 'A', 'type' => 'simple', 'name' => 'A', 'parentIds' => [70]]],
            'second' => [7 => ['productId' => 7, 'storeViewCode' => 'second', 'sku' => 'A', 'type' => 'simple', 'name' => 'A second']],
        ], $this->upserts);
        $this->assertSame(['default' => ['added' => [7]], 'second' => []], $this->listChanges);
        $this->assertSame(['default' => [8], 'second' => []], $this->deletes);
    }

    public function testTheCustomAttributesAreTheValuePerAttributeCode(): void
    {
        $this->write([[
            'productId' => 7,
            'storeViewCode' => 'default',
            'sku' => 'A',
            'customAttributes' => [
                ['attributeCode' => 'color', 'value' => '51'],
                ['attributeCode' => 'note', 'value' => null],
                ['attributeCode' => 'size'],
            ],
            // The exporter's own attribute slice holds the same values with option labels.
            'attributes' => [['attributeCode' => 'color', 'value' => ['Red'], 'valueId' => ['51']]],
        ]]);

        $this->assertSame(['color' => '51', 'note' => null, 'size' => null], $this->upserts['default'][7]['customAttributes']);
        $this->assertArrayNotHasKey('attributes', $this->upserts['default'][7]);
    }

    public function testAnImageTypeIsTheMediaFileOfItsUrl(): void
    {
        $this->write([[
            'productId' => 7,
            'storeViewCode' => 'default',
            'sku' => 'A',
            'image' => ['url' => 'https://example.com/media/catalog/product/w/t/wt09.jpg', 'label' => 'A'],
            'thumbnail' => ['url' => null],
            'swatchImage' => ['url' => 'https://example.com/media/catalog/product/w/t/wt09.jpg'],
        ]]);

        $this->assertSame('/w/t/wt09.jpg', $this->upserts['default'][7]['image']);
        $this->assertSame('no_selection', $this->upserts['default'][7]['thumbnail']);
        $this->assertArrayNotHasKey('smallImage', $this->upserts['default'][7]);
        $this->assertArrayNotHasKey('swatchImage', $this->upserts['default'][7]);
    }

    public function testTheGalleryIsStoredOnceAsMediaFiles(): void
    {
        $this->write([[
            'productId' => 7,
            'storeViewCode' => 'default',
            'sku' => 'A',
            'media_gallery' => [
                ['url' => 'https://example.com/media/catalog/product/w/t/wt09.jpg', 'label' => null, 'types' => ['image'], 'sort_order' => 1],
                ['url' => 'https://example.com/media/catalog/product/w/t/wt09_alt.jpg', 'label' => 'Back', 'types' => [], 'sort_order' => 2],
            ],
            // The modern gallery slices hold the same entries.
            'images' => [['resource' => ['url' => 'https://example.com/media/catalog/product/w/t/wt09.jpg']]],
            'videos' => [],
        ]]);

        $this->assertSame([
            ['file' => '/w/t/wt09.jpg', 'types' => ['image'], 'sort_order' => 1],
            ['file' => '/w/t/wt09_alt.jpg', 'label' => 'Back', 'sort_order' => 2],
        ], $this->upserts['default'][7]['media_gallery']);
        $this->assertArrayNotHasKey('images', $this->upserts['default'][7]);
        $this->assertArrayNotHasKey('videos', $this->upserts['default'][7]);
    }

    public function testAUrlRewriteIsItsRequestPath(): void
    {
        $this->write([[
            'productId' => 7,
            'storeViewCode' => 'default',
            'sku' => 'A',
            'urlRewrites' => [
                ['url' => 'https://example.com/breathe-easy-tank.html', 'parameters' => [['name' => 'id', 'value' => '7']]],
                ['url' => 'https://example.com/tanks/breathe-easy-tank.html'],
            ],
        ]]);

        $this->assertSame([
            ['url' => 'breathe-easy-tank.html', 'parameters' => [['name' => 'id', 'value' => '7']]],
            ['url' => 'tanks/breathe-easy-tank.html'],
        ], $this->upserts['default'][7]['urlRewrites']);
    }

    public function testTheCategoryDataBecomesTheCategoryIds(): void
    {
        $this->write([[
            'productId' => 7,
            'storeViewCode' => 'default',
            'sku' => 'A',
            'categoryData' => [
                ['categoryId' => '24', 'categoryPath' => '1/2/24', 'productPosition' => 3],
                ['categoryId' => '25', 'categoryPath' => '1/2/25'],
                ['categoryPath' => '1/2'],
            ],
        ]]);

        $this->assertSame([24, 25], $this->upserts['default'][7]['categoryIds']);
        $this->assertArrayNotHasKey('categoryData', $this->upserts['default'][7]);
    }
}
