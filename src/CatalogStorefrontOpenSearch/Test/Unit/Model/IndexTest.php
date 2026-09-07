<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\EntityMappings;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Client;
use GraphCommerce\CatalogStorefrontOpenSearch\Model\Index;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    private const MAPPING = [
        'dynamic' => false,
        'properties' => [
            'sku' => ['type' => 'keyword'],
            'stock' => ['properties' => ['isSalable' => ['type' => 'boolean']]],
            'priceIndex' => ['type' => 'nested', 'properties' => [
                'group' => ['type' => 'keyword'],
                'final' => ['type' => 'float'],
            ]],
        ],
    ];

    private function index(Client&MockObject $client): Index
    {
        $client->method('indexName')->willReturn('cs_product_default');

        return new Index($client, new EntityMappings(['product' => [
            'sku' => 'keyword',
            'stock.isSalable' => 'boolean',
            'priceIndex' => ['type' => 'nested', 'fields' => ['group' => 'keyword', 'final' => 'float']],
        ]]));
    }

    public function testCreatesTheIndexOnceBehindBothAliasesWithTheDeclaredFieldsMapped(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('indexExists')->willReturn(false);
        $client->expects(self::once())->method('createIndex')->with(
            self::matchesRegularExpression('/^cs_product_default_\d{14}_[0-9a-f]{4}$/'),
            self::MAPPING,
            ['cs_product_default', 'cs_product_default_write']
        );
        $index = $this->index($client);

        self::assertSame('cs_product_default_write', $index->ensure('product', 'default'));
        self::assertSame('cs_product_default_write', $index->ensure('product', 'default'));
    }

    public function testWritesToAnIndexCreatedBeforeTheAliases(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('indexExists')->willReturnMap([['cs_product_default_write', false], ['cs_product_default', true]]);
        $client->expects(self::never())->method('createIndex');

        self::assertSame('cs_product_default', $this->index($client)->ensure('product', 'default'));
    }

    public function testStagingMovesTheWriteAliasAndDropsAnAbandonedStage(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('aliasTargets')->willReturnMap([
            ['cs_product_default_write', ['cs_product_default_old', 'cs_product_default_abandoned']],
            ['cs_product_default', ['cs_product_default_old']],
        ]);
        $client->expects(self::once())->method('createIndex')->with(self::isString(), self::MAPPING, []);
        $client->expects(self::once())->method('moveAlias')->with(
            'cs_product_default_write',
            ['cs_product_default_old', 'cs_product_default_abandoned'],
            self::matchesRegularExpression('/^cs_product_default_\d{14}_[0-9a-f]{4}$/')
        );
        $client->expects(self::once())->method('deleteIndex')->with('cs_product_default_abandoned');
        $index = $this->index($client);

        $index->stage('product', 'default');
        self::assertSame('cs_product_default_write', $index->ensure('product', 'default'));
    }

    public function testPromotingMovesTheReadAliasAndDeletesTheOldIndex(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('aliasTargets')->willReturnMap([
            ['cs_product_default_write', ['cs_product_default_new']],
            ['cs_product_default', ['cs_product_default_old']],
        ]);
        $client->expects(self::once())->method('moveAlias')->with('cs_product_default', ['cs_product_default_old'], 'cs_product_default_new');
        $client->expects(self::once())->method('deleteIndex')->with('cs_product_default_old');

        $this->index($client)->promote('product', 'default');
    }

    public function testPromotingReplacesAnIndexCreatedBeforeTheAliases(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('aliasTargets')->willReturnMap([
            ['cs_product_default_write', ['cs_product_default_new']],
            ['cs_product_default', []],
        ]);
        $client->method('indexExists')->with('cs_product_default')->willReturn(true);
        $client->expects(self::once())->method('deleteIndex')->with('cs_product_default');
        $client->expects(self::once())->method('moveAlias')->with('cs_product_default', [], 'cs_product_default_new');

        $this->index($client)->promote('product', 'default');
    }
}
