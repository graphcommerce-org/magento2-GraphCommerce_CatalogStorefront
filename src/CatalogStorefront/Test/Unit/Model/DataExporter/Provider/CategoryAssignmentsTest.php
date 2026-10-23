<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefront\Model\DataExporter\Provider\CategoryAssignments;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class CategoryAssignmentsTest extends TestCase
{
    public function testEveryAssignmentIsOneRowPerProductAndStoreViewInCategoryIdOrder(): void
    {
        // The root (2) is an assignment as well, as core's getCategoryIds() returns it.
        $provider = $this->provider([
            ['product_id' => '1812', 'category_id' => '2'],
            ['product_id' => '1812', 'category_id' => '30'],
            ['product_id' => '6', 'category_id' => '4'],
        ]);

        $output = $provider->get([
            ['productId' => '1812', 'storeViewCode' => 'default'],
            ['productId' => '6', 'storeViewCode' => 'default'],
            ['productId' => '7', 'storeViewCode' => 'default'],
            ['productId' => '1812', 'storeViewCode' => 'second'],
        ]);

        self::assertSame([
            'default_1812_2' => ['productId' => '1812', 'storeViewCode' => 'default', 'categoryAssignmentIds' => 2],
            'default_1812_30' => ['productId' => '1812', 'storeViewCode' => 'default', 'categoryAssignmentIds' => 30],
            'default_6_4' => ['productId' => '6', 'storeViewCode' => 'default', 'categoryAssignmentIds' => 4],
            'second_1812_2' => ['productId' => '1812', 'storeViewCode' => 'second', 'categoryAssignmentIds' => 2],
            'second_1812_30' => ['productId' => '1812', 'storeViewCode' => 'second', 'categoryAssignmentIds' => 30],
        ], $output);
    }

    public function testNoProductsMeansNoQuery(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');

        self::assertSame([], (new CategoryAssignments($resource))->get([]));
    }

    private function provider(array $rows): CategoryAssignments
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

        return new CategoryAssignments($resource);
    }
}
