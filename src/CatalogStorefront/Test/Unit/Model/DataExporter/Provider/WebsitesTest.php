<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefront\Model\DataExporter\Provider\Websites;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class WebsitesTest extends TestCase
{
    public function testEveryAssignmentIsOneRowPerProductAndStoreViewInWebsiteIdOrder(): void
    {
        $provider = $this->provider([
            ['product_id' => '1', 'website_id' => '1'],
            ['product_id' => '1', 'website_id' => '2'],
            ['product_id' => '2', 'website_id' => '2'],
        ]);

        $output = $provider->get([
            ['productId' => '1', 'storeViewCode' => 'default'],
            ['productId' => '2', 'storeViewCode' => 'default'],
            ['productId' => '3', 'storeViewCode' => 'default'],
            ['productId' => '1', 'storeViewCode' => 'second'],
        ]);

        self::assertSame([
            'default_1_1' => ['productId' => '1', 'storeViewCode' => 'default', 'websiteIds' => 1],
            'default_1_2' => ['productId' => '1', 'storeViewCode' => 'default', 'websiteIds' => 2],
            'default_2_2' => ['productId' => '2', 'storeViewCode' => 'default', 'websiteIds' => 2],
            'second_1_1' => ['productId' => '1', 'storeViewCode' => 'second', 'websiteIds' => 1],
            'second_1_2' => ['productId' => '1', 'storeViewCode' => 'second', 'websiteIds' => 2],
        ], $output);
    }

    public function testNoProductsMeansNoQuery(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');

        self::assertSame([], (new Websites($resource))->get([]));
    }

    private function provider(array $rows): Websites
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new Websites($resource);
    }
}
