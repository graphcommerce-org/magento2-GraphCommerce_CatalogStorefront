<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\EntityMappings;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Index;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    public function testCreatesTheIndexOnceWithTheDeclaredFieldsMapped(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('indexName')->willReturn('cs_product_default');
        $client->method('indexExists')->willReturn(false);
        $client->expects(self::once())->method('createIndex')->with('cs_product_default', [
            'dynamic' => false,
            'properties' => [
                'sku' => ['type' => 'keyword'],
                'stock' => ['properties' => ['isSalable' => ['type' => 'boolean']]],
                'priceIndex' => ['type' => 'nested', 'properties' => [
                    'group' => ['type' => 'keyword'],
                    'final' => ['type' => 'float'],
                ]],
            ],
        ]);
        $index = new Index($client, new EntityMappings(['product' => [
            'sku' => 'keyword',
            'stock.isSalable' => 'boolean',
            'priceIndex' => ['type' => 'nested', 'fields' => ['group' => 'keyword', 'final' => 'float']],
        ]]));

        self::assertSame('cs_product_default', $index->ensure('product', 'default'));
        self::assertSame('cs_product_default', $index->ensure('product', 'default'));
    }
}
