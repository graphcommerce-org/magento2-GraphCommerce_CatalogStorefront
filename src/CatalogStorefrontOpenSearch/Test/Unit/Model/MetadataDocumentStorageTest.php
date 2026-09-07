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
    public function testDeclaredFieldsComeFromDocValuesAndOthersFromTheSource(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('indexName')->willReturn('cs_category_default');
        $bodies = [];
        $client->method('search')->willReturnCallback(static function (string $index, array $body) use (&$bodies): array {
            $bodies[] = $body;
            return isset($body['docvalue_fields'])
                ? ['hits' => ['hits' => [
                    ['_id' => '9', 'fields' => ['name' => ['Shoes'], 'children' => [10, 11]]],
                    ['_id' => '10', 'fields' => ['name' => ['Boots'], 'children' => [12]]],
                    ['_id' => '11', 'fields' => ['name' => ['Sandals']]],
                ]]]
                : ['hits' => ['hits' => [['_id' => '9', '_source' => ['filterPriceRange' => 50]]]]];
        });
        $storage = new MetadataDocumentStorage($client, $this->createMock(Index::class), new EntityMappings([
            'category' => ['name' => 'keyword', 'children' => 'integer'],
        ]));

        self::assertSame(
            ['9' => ['name' => 'Shoes', 'children' => [10, 11]], '10' => ['name' => 'Boots', 'children' => 12], '11' => ['name' => 'Sandals']],
            $storage->get('category', 'default', [9, 10, 11], ['name', 'children'])
        );
        self::assertSame(['9' => ['filterPriceRange' => 50]], $storage->get('category', 'default', [9], ['filterPriceRange']));
        self::assertSame([], $storage->get('category', 'default', [], ['name']));

        self::assertSame(['name', 'children'], $bodies[0]['docvalue_fields']);
        self::assertFalse($bodies[0]['_source']);
        self::assertSame(['filterPriceRange'], $bodies[1]['_source']);
        self::assertSame(['9', '10', '11'], $bodies[0]['query']['bool']['filter'][0]['ids']['values']);
    }
}
