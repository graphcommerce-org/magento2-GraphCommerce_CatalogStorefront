<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\Feeds;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\SourceFeedCounts;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

final class SourceFeedCountsTest extends TestCase
{
    public function testSourceReceiptsAreIndependentAndPendingDoesNotClaimActiveProcessing(): void
    {
        $subject = $this->subject([
            'product' => [['scope' => 'default', 'imported' => '47', 'pending' => '3', 'failed' => '2']],
            'category' => [['scope' => 'default', 'imported' => '39', 'pending' => '0', 'failed' => '0']],
            'attribute' => [['scope' => 'default', 'imported' => '88', 'pending' => '0', 'failed' => '0']],
        ]);
        $result = $subject->get();
        self::assertSame('47', $result['default']['feedProducts']['value']);
        self::assertSame(['3 PENDING', '2 FAILED'], array_column($result['default']['feedProducts']['badges'], 'label'));
        self::assertSame(['pending', 'failed'], array_column($result['default']['feedProducts']['badges'], 'tone'));
        self::assertSame('39', $result['default']['feedCategories']['value']);
        self::assertSame('88', $result['default']['feedAttributes']['value']);
        self::assertSame([], $result['default']['feedAttributes']['badges']);
        self::assertStringContainsString('pending does not mean actively processing', $result['default']['feedProducts']['description']);
        self::assertSame($result, $subject->get(), 'Only one receipt snapshot is read during a listing request.');
    }

    public function testMissingOrUnreadableFeedDoesNotMasqueradeAsAnEmptyFeed(): void
    {
        $result = $this->subject([
            'product' => new \RuntimeException('Feed temporarily unavailable'),
            'category' => [],
        ])->get();
        self::assertArrayNotHasKey('feedProducts', $result['*']);
        self::assertArrayNotHasKey('feedAttributes', $result['*']);
        self::assertSame('0', $result['*']['feedCategories']['value']);
    }

    public function testStoreScopesAreKeptSeparate(): void
    {
        $result = $this->subject(['product' => [
            ['scope' => 'default', 'imported' => '47', 'pending' => '0', 'failed' => '0'],
            ['scope' => 'nl', 'imported' => '24', 'pending' => '0', 'failed' => '5'],
        ]])->get();
        self::assertSame('47', $result['default']['feedProducts']['value']);
        self::assertSame([], $result['default']['feedProducts']['badges']);
        self::assertSame('24', $result['nl']['feedProducts']['value']);
        self::assertSame(['5 FAILED'], array_column($result['nl']['feedProducts']['badges'], 'label'));
    }

    /** Each entity is a separate feed receipt query, never a count of the native catalog entity tables. */
    private function subject(array $outcomes): SourceFeedCounts
    {
        $ids = ['product' => 'catalog_data_exporter_products', 'category' => 'catalog_data_exporter_categories',
            'attribute' => 'catalog_data_exporter_product_attributes'];
        $metadata = [];
        $responses = [];
        foreach ($ids as $entity => $id) {
            if (!array_key_exists($entity, $outcomes)) continue;
            $feed = $this->createStub(FeedIndexMetadata::class);
            $feed->method('getFeedTableName')->willReturn('test_' . $entity . '_feed');
            $metadata[$entity][$id] = $feed;
            $responses[] = $outcomes[$entity];
        }
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'group'] as $method) $select->method($method)->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::exactly(count($responses)))->method('fetchAll')->willReturnCallback(
            static function () use (&$responses): array {
                $response = array_shift($responses);
                if ($response instanceof \Throwable) throw $response;
                return $response;
            }
        );
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new SourceFeedCounts($resource, new Feeds($metadata));
    }
}
