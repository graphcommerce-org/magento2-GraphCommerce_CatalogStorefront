<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\ChildPriceRanges;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Index;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\ProductDocumentStorage;
use PHPUnit\Framework\TestCase;

class ProductDocumentStorageTest extends TestCase
{
    public function testAReadBeyondTheWindowIsOneSearchPerWindow(): void
    {
        $ids = range(1, 10001);
        $requests = [];
        $client = $this->createStub(Client::class);
        $client->method('indexName')->willReturn('cs_product_default');
        $client->method('multiSearch')->willReturnCallback(static function (array $searches) use (&$requests): array {
            $requests = $searches;

            return array_map(static fn(array $search) => ['hits' => ['hits' => array_map(
                static fn(string $id) => ['_id' => $id, '_source' => ['id' => (int)$id]],
                $search[1]['query']['ids']['values']
            )]], $searches);
        });
        $storage = new ProductDocumentStorage($client, $this->createStub(Index::class), new ChildPriceRanges());

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

    public function testPriceDataDelegatesChildRangesAndKeepsBundleResponses(): void
    {
        $built = [];
        $parsed = [];
        $ranges = $this->createMock(ChildPriceRanges::class);
        $ranges->expects(self::exactly(2))->method('build')
            ->willReturnCallback(static function (string $field, array $ids, string $group) use (&$built): array {
                $built[] = [$field, $ids, $group];

                return ['range' => $field];
            });
        $ranges->expects(self::exactly(2))->method('parse')
            ->willReturnCallback(static function (array $response) use (&$parsed): array {
                $parsed[] = $response;

                return $response['parsed'];
            });
        $requests = [];
        $client = $this->createMock(Client::class);
        $client->method('indexName')->willReturn('cs_product_default');
        $client->expects(self::once())->method('multiSearch')
            ->willReturnCallback(static function (array $searches) use (&$requests): array {
                $requests = $searches;

                return [
                    ['parsed' => [7 => ['salable' => ['configurable']]]],
                    ['parsed' => [7 => ['all' => ['grouped']]]],
                    ['hits' => ['hits' => [[
                        '_source' => ['sku' => 'B-1', 'bundleParentIds' => [7], 'priceIndex' => []],
                    ]]]],
                    ['hits' => ['hits' => [[
                        '_id' => '7',
                        '_source' => ['optionsV2' => [['type' => 'bundle']]],
                    ]]]],
                ];
            });
        $storage = new ProductDocumentStorage($client, $this->createStub(Index::class), $ranges);

        $actual = $storage->priceData('default', [7], '2');

        self::assertSame([
            ['parentIds', ['7'], '2'],
            ['groupedParentIds', ['7'], '2'],
        ], $built);
        self::assertSame([
            ['parsed' => [7 => ['salable' => ['configurable']]]],
            ['parsed' => [7 => ['all' => ['grouped']]]],
        ], $parsed);
        self::assertSame('cs_product_default', $requests[0][0]);
        self::assertSame(['range' => 'parentIds'], $requests[0][1]);
        self::assertSame(['range' => 'groupedParentIds'], $requests[1][1]);
        self::assertSame([
            'configurable' => [7 => ['salable' => ['configurable']]],
            'grouped' => [7 => ['all' => ['grouped']]],
            'bundle' => [7 => ['B-1' => ['sku' => 'B-1', 'bundleParentIds' => [7], 'priceIndex' => []]]],
            'bundleOptions' => [7 => ['optionsV2' => [['type' => 'bundle']]]],
        ], $actual);
    }
}
