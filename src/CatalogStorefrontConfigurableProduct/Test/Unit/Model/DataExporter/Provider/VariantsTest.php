<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\DataExporter\Provider\Variants;
use Magento\ConfigurableProductDataExporter\Model\Query\VariantsQuery;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class VariantsTest extends TestCase
{
    public function testEveryChildIsOneRowOfTheParentAndStoreViewTheBatchAsksFor(): void
    {
        $query = $this->createMock(VariantsQuery::class);
        $query->expects(self::once())
            ->method('getQuery')
            ->with(['productId' => ['62', '70']])
            ->willReturn($this->createMock(Select::class));
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn([
            ['storeViewCode' => 'default', 'productId' => '62', 'sku' => 'red-s'],
            ['storeViewCode' => 'default', 'productId' => '62', 'sku' => 'red-m'],
            // The query yields every store view of the child's websites.
            ['storeViewCode' => 'second', 'productId' => '62', 'sku' => 'red-s'],
            ['storeViewCode' => 'default', 'productId' => '70', 'sku' => 'blue-s'],
        ]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $output = (new Variants($resource, $query))->get([
            ['productId' => '62', 'storeViewCode' => 'default'],
            ['productId' => '70', 'storeViewCode' => 'default'],
        ]);

        self::assertSame([
            'default/62/red-m' => ['productId' => '62', 'storeViewCode' => 'default', 'variants' => ['sku' => 'red-m']],
            'default/62/red-s' => ['productId' => '62', 'storeViewCode' => 'default', 'variants' => ['sku' => 'red-s']],
            'default/70/blue-s' => ['productId' => '70', 'storeViewCode' => 'default', 'variants' => ['sku' => 'blue-s']],
        ], $output);
    }

    public function testNoProductsMeansNoQuery(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');

        self::assertSame([], (new Variants($resource, $this->createMock(VariantsQuery::class)))->get([]));
    }
}
