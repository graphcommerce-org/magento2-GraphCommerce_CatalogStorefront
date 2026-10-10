<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Cron;

use GraphCommerce\CatalogStorefront\Cron\RefreshSpecialPriceWindows;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use PHPUnit\Framework\TestCase;

class RefreshSpecialPriceWindowsTest extends TestCase
{
    public function testTheProductsWhoseWindowTurnsAroundTodayAreExportedAgain(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects(self::once())->method('reindexList')->with([4, 9]);
        $this->cron(['4', '9'], $indexer)->execute();
    }

    public function testNothingIsExportedWhenNoWindowTurns(): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects(self::never())->method('reindexList');
        $this->cron([], $indexer)->execute();
    }

    private function cron(array $productIds, IndexerInterface $indexer): RefreshSpecialPriceWindows
    {
        $select = $this->createMock(Select::class);
        foreach (['distinct', 'from', 'join', 'where'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn($productIds);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);

        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getId')->willReturn(77);
        $eav = $this->createMock(EavConfig::class);
        $eav->method('getAttribute')->willReturn($attribute);

        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('get')->with(RefreshSpecialPriceWindows::PRICE_FEED_INDEXER)->willReturn($indexer);

        return new RefreshSpecialPriceWindows($resource, $pool, $eav, $registry);
    }
}
