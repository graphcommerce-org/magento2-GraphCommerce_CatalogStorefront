<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Document\Delivery;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use Magento\DataExporter\Model\FeedExportStatus;
use Magento\DataExporter\Model\FeedExportStatusBuilder;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeliveryTest extends TestCase
{
    private ?int $statusCode = null;

    private function delivery(FeedWriterInterface $writer, ManagerInterface $events, CacheInterface $cache): Delivery
    {
        $config = $this->createMock(Config::class);
        $config->method('indexing')->willReturn(true);
        $statusBuilder = $this->createMock(FeedExportStatusBuilder::class);
        $statusBuilder->method('build')->willReturnCallback(function (int $code) {
            $this->statusCode = $code;

            return $this->createMock(FeedExportStatus::class);
        });
        $contextFactory = $this->createMock(CacheContextFactory::class);
        $contextFactory->method('create')->willReturnCallback(static fn() => new CacheContext());

        return new Delivery(
            $statusBuilder,
            $config,
            $this->createMock(LoggerInterface::class),
            $contextFactory,
            $events,
            $cache,
            ['products' => $writer],
            ['products' => ['cat_p' => ['productId', 'parentId']]]
        );
    }

    private function metadata(string $feed): FeedIndexMetadata
    {
        return $this->createConfiguredMock(FeedIndexMetadata::class, ['getFeedName' => $feed]);
    }

    public function testPurgesTheTagsOfTheBatchAfterTheWrite(): void
    {
        $rows = [
            ['productId' => 7, 'storeViewCode' => 'default'],
            ['productId' => 7, 'storeViewCode' => 'other', 'parentId' => 9],
            ['productId' => 8, 'deleted' => true],
        ];
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->expects(self::once())->method('write')->with($rows);
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::once())->method('dispatch')->with(
            'clean_cache_by_tags',
            self::callback(static fn(array $data) => $data['object']->getIdentities() === ['cat_p_7', 'cat_p_9', 'cat_p_8'])
        );
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('clean')->with(['cat_p_7', 'cat_p_9', 'cat_p_8']);

        $this->delivery($writer, $events, $cache)->export($rows, $this->metadata('products'));
        self::assertSame(200, $this->statusCode);
    }

    public function testAFeedWithoutAWriterPurgesNothing(): void
    {
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->expects(self::never())->method('write');
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::never())->method('dispatch');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $this->delivery($writer, $events, $cache)->export([['productId' => 1]], $this->metadata('orders'));
    }

    public function testAFailedWriteAsksForARetryAndPurgesNothing(): void
    {
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->method('write')->willThrowException(new \RuntimeException('down'));
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $this->delivery($writer, $this->createMock(ManagerInterface::class), $cache)
            ->export([['productId' => 1]], $this->metadata('products'));
        self::assertSame(500, $this->statusCode);
    }
}
