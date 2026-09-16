<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefrontReview\Model\DataExporter\Provider\ProductReviewSummaries;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ProductReviewSummariesTest extends TestCase
{
    public function testEveryNamedProductGetsTheSummaryOfEveryStoreView(): void
    {
        $rows = $this->provider(
            [['store_id' => 1, 'entity_pk_value' => 7, 'review_count' => '2']],
            [['store_id' => 1, 'entity_pk_value' => 7, 'sum_percent' => '140', 'vote_count' => '2',
                'min_percent' => '40', 'max_percent' => '100']],
        )->get([['productId' => 7], ['productId' => 8]]);

        self::assertSame([
            ['productId' => 7, 'productReviewSummaries' => ['storeViewCode' => 'default', 'state' => 'known', 'count' => 2, 'average' => '70']],
            ['productId' => 7, 'productReviewSummaries' => ['storeViewCode' => 'nl', 'state' => 'known', 'count' => 0, 'average' => null]],
            ['productId' => 8, 'productReviewSummaries' => ['storeViewCode' => 'default', 'state' => 'known', 'count' => 0, 'average' => null]],
            ['productId' => 8, 'productReviewSummaries' => ['storeViewCode' => 'nl', 'state' => 'known', 'count' => 0, 'average' => null]],
        ], array_values($rows));
    }

    public function testAnApprovedReviewWithoutAVoteLeavesTheAverageUnknown(): void
    {
        $rows = $this->provider([['store_id' => 1, 'entity_pk_value' => 7, 'review_count' => '1']], [])->get([['productId' => 7]]);

        self::assertSame(['storeViewCode' => 'default', 'state' => 'unknown', 'count' => null, 'average' => null],
            $rows['7_default']['productReviewSummaries']);
    }

    public function testAVoteWithoutAnApprovedReviewIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/without an approved review/');

        $this->provider([], [['store_id' => 1, 'entity_pk_value' => 7, 'sum_percent' => '40', 'vote_count' => '1',
            'min_percent' => '40', 'max_percent' => '40']])->get([['productId' => 7]]);
    }

    private function provider(array $counts, array $votes): ProductReviewSummaries
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'join', 'where', 'group', 'columns'] as $method) {
            $select->method($method)->willReturn($select);
        }
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $calls = 0;
        $connection->method('fetchAll')->willReturnCallback(static function () use (&$calls, $counts, $votes): array {
            return $calls++ === 0 ? $counts : $votes;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([
            $this->createConfiguredStub(StoreInterface::class, ['getId' => 1, 'getCode' => 'default']),
            $this->createConfiguredStub(StoreInterface::class, ['getId' => 2, 'getCode' => 'nl']),
        ]);

        return new ProductReviewSummaries($resource, $stores);
    }
}
