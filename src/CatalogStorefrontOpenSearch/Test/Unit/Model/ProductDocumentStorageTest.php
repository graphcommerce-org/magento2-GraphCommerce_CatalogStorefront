<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Index;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\ProductDocumentStorage;
use PHPUnit\Framework\TestCase;

class ProductDocumentStorageTest extends TestCase
{
    public function testAReadBeyondTheWindowIsOneSearchPerWindow(): void
    {
        $ids = range(1, 10001);
        $requests = [];
        $client = $this->createMock(Client::class);
        $client->method('indexName')->willReturn('cs_product_default');
        $client->method('multiSearch')->willReturnCallback(static function (array $searches) use (&$requests): array {
            $requests = $searches;

            return array_map(static fn(array $search) => ['hits' => ['hits' => array_map(
                static fn(string $id) => ['_id' => $id, '_source' => ['id' => (int)$id]],
                $search[1]['query']['ids']['values']
            )]], $searches);
        });
        $storage = new ProductDocumentStorage($client, $this->createMock(Index::class));

        [$documents, $priceData] = $storage->listing('default', $ids, null);

        self::assertCount(2, $requests);
        self::assertSame(10000, $requests[0][1]['size']);
        self::assertSame(1, $requests[1][1]['size']);
        self::assertSame(['10001'], $requests[1][1]['query']['ids']['values']);
        self::assertSame('cs_product_default', $requests[1][0]);
        self::assertCount(10001, $documents);
        self::assertSame(['id' => 10001], $documents[10001]);
        self::assertSame([], $priceData);

        $documents = $storage->get('default', [1, 2], ['sku']);
        self::assertSame(['sku'], $requests[0][1]['_source']);
        self::assertSame([1 => ['id' => 1], 2 => ['id' => 2]], $documents);
    }
}
