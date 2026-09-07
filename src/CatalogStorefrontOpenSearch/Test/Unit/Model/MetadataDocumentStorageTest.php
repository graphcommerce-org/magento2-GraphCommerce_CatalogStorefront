<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\EntityMappings;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Index;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\MetadataDocumentStorage;
use PHPUnit\Framework\TestCase;

class MetadataDocumentStorageTest extends TestCase
{
    public function testABatchReadsDeclaredFieldsFromDocValuesAndTheRestFromTheSourceInOneRequest(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('indexName')->willReturnCallback(static fn(string $entity, string $store) => "cs_{$entity}_{$store}");
        $requests = [];
        $client->method('multiSearch')->willReturnCallback(static function (array $searches) use (&$requests): array {
            $requests[] = $searches;
            return array_map(static fn(array $search) => match (true) {
                isset($search[1]['docvalue_fields']) => ['hits' => ['hits' => [
                    ['_id' => '9', 'fields' => ['name' => ['Shoes'], 'children' => [10, 11]]],
                    ['_id' => '10', 'fields' => ['name' => ['Boots'], 'children' => [12]]],
                    ['_id' => '11', 'fields' => ['name' => ['Sandals']]],
                ]]],
                isset($search[1]['query']['bool']['should']) => ['hits' => ['hits' => [['_id' => 'color', '_source' => ['label' => 'Color']]]]],
                default => ['hits' => ['hits' => [['_id' => '9', '_source' => ['filterPriceRange' => 50]]]]],
            }, $searches);
        });
        $storage = new MetadataDocumentStorage($client, $this->createMock(Index::class), new EntityMappings([
            'category' => ['name' => 'keyword', 'children' => 'integer'],
        ]));

        self::assertSame(
            [
                ['color' => ['label' => 'Color']],
                ['9' => ['name' => 'Shoes', 'children' => [10, 11]], '10' => ['name' => 'Boots', 'children' => 12], '11' => ['name' => 'Sandals']],
            ],
            $storage->batch('default', [
                ['entity' => 'attribute', 'any' => [['options.id' => [5]]]],
                ['entity' => 'category', 'ids' => [9, 10, 11], 'fields' => ['name', 'children']],
            ])
        );
        self::assertSame(['9' => ['filterPriceRange' => 50]], $storage->get('category', 'default', [9], ['filterPriceRange']));
        self::assertSame([], $storage->get('category', 'default', [], ['name']));
        self::assertSame(['color' => ['label' => 'Color']], $storage->any('attribute', 'default', [['options.id' => [5]]]));

        self::assertCount(3, $requests);
        [$any, $docValues] = $requests[0];
        self::assertSame('cs_attribute_default', $any[0]);
        self::assertSame([['terms' => ['options.id' => ['5']]]], $any[1]['query']['bool']['should'][0]['bool']['filter']);
        self::assertSame('cs_category_default', $docValues[0]);
        self::assertSame(['name', 'children'], $docValues[1]['docvalue_fields']);
        self::assertFalse($docValues[1]['_source']);
        self::assertSame(['9', '10', '11'], $docValues[1]['query']['bool']['filter'][0]['ids']['values']);
        self::assertSame(['filterPriceRange'], $requests[1][0][1]['_source']);
    }
}
