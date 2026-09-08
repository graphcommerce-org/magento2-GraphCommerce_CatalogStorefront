<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\CompositeLinks;
use GraphCommerce\CatalogStorefront\Model\Document\Writer\Products;
use GraphCommerce\CatalogStorefrontApi\Document\ProductDocumentFieldInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * The document contract of the products feed: a batch in, per store view the
 * documents out, with the feed slices of the other feeds left out, the
 * fields applied, the composite links merged and the deletions routed.
 */
class ProductsTest extends TestCase
{
    public function testWritesTheBatchAsDocumentsPerStoreView(): void
    {
        $batch = [
            ['productId' => 7, 'storeViewCode' => 'default', 'sku' => 'A', 'type' => 'simple', 'prices' => null, 'stock' => null, 'name' => 'A'],
            ['productId' => 8, 'storeViewCode' => 'default', 'sku' => 'B', 'type' => 'simple', 'deleted' => true],
            ['productId' => 7, 'storeViewCode' => 'second', 'sku' => 'A', 'type' => 'simple', 'name' => 'A second'],
        ];
        $field = new class implements ProductDocumentFieldInterface {
            public function add(string $storeViewCode, array $documents): array
            {
                foreach ($documents as &$document) {
                    $document['store'] = $storeViewCode;
                }

                return $documents;
            }
        };
        $links = $this->createMock(CompositeLinks::class);
        $links->method('upserts')->willReturnCallback(
            static fn(string $store, array $documents, array $deleted) => $store === 'default'
                ? [[7 => ['parentIds' => [70]]], ['added' => [7]]]
                : [[], []]
        );
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $upserts = [];
        $storage->method('upsert')->willReturnCallback(static function (string $store, array $documents) use (&$upserts): void {
            $upserts[$store] = $documents;
        });
        $listChanges = [];
        $storage->method('updateLists')->willReturnCallback(static function (string $store, array $changes) use (&$listChanges): void {
            $listChanges[$store] = $changes;
        });
        $deletes = [];
        $storage->method('delete')->willReturnCallback(static function (string $store, array $ids) use (&$deletes): void {
            $deletes[$store] = $ids;
        });

        (new Products($storage, $links, [$field]))->write($batch);

        $this->assertSame([
            'default' => [7 => ['productId' => 7, 'storeViewCode' => 'default', 'sku' => 'A', 'type' => 'simple', 'name' => 'A', 'store' => 'default', 'parentIds' => [70]]],
            'second' => [7 => ['productId' => 7, 'storeViewCode' => 'second', 'sku' => 'A', 'type' => 'simple', 'name' => 'A second', 'store' => 'second']],
        ], $upserts);
        $this->assertSame(['default' => ['added' => [7]], 'second' => []], $listChanges);
        $this->assertSame(['default' => [8], 'second' => []], $deletes);
    }
}
