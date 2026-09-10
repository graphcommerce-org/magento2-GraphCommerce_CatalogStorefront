<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Document\Delivery;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriteAcceptanceInterface;
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

    private function delivery(
        FeedWriterInterface $writer,
        ManagerInterface $events,
        CacheInterface $cache,
        ?FeedWriteAcceptanceInterface $acceptor = null,
        bool $indexing = true,
    ): Delivery {
        $config = $this->createMock(Config::class);
        $config->method('indexing')->willReturn($indexing);
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
            ['products' => ['cat_p' => ['productId', 'parentId']]],
            $acceptor === null ? [] : ['products' => $acceptor],
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
        $acceptor = $this->createMock(FeedWriteAcceptanceInterface::class);
        $acceptor->expects(self::never())->method('accept');
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::never())->method('dispatch');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $this->delivery($writer, $events, $cache, $acceptor)
            ->export([['productId' => 1]], $this->metadata('orders'));
    }

    public function testAFailedWriteAsksForARetryAndPurgesNothing(): void
    {
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->method('write')->willThrowException(new \RuntimeException('down'));
        $acceptor = $this->createMock(FeedWriteAcceptanceInterface::class);
        $acceptor->expects(self::never())->method('accept');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $this->delivery($writer, $this->createMock(ManagerInterface::class), $cache, $acceptor)
            ->export([['productId' => 1]], $this->metadata('products'));
        self::assertSame(500, $this->statusCode);
    }

    public function testIndexingOffSkipsTheWriterAcceptorAndPurge(): void
    {
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->expects(self::never())->method('write');
        $acceptor = $this->createMock(FeedWriteAcceptanceInterface::class);
        $acceptor->expects(self::never())->method('accept');
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::never())->method('dispatch');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $this->delivery($writer, $events, $cache, $acceptor, false)
            ->export([['productId' => 1]], $this->metadata('products'));

        self::assertSame(200, $this->statusCode);
    }

    public function testAcceptsTheExactWrittenBatchBeforePurging(): void
    {
        $rows = [['productId' => 7, 'storeViewCode' => 'default']];
        $metadata = $this->metadata('products');
        $order = [];
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->expects(self::once())->method('write')->with($rows)->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'write';
            },
        );
        $acceptor = $this->createMock(FeedWriteAcceptanceInterface::class);
        $acceptor->expects(self::once())->method('accept')->with($rows, $metadata)->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'accept';
            },
        );
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::once())->method('dispatch')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'purge';
            },
        );

        $this->delivery($writer, $events, $this->createMock(CacheInterface::class), $acceptor)
            ->export($rows, $metadata);

        self::assertSame(['write', 'accept', 'purge'], $order);
        self::assertSame(200, $this->statusCode);
    }

    public function testAcceptanceFailureEscapesAndPreventsPurgeAndSuccess(): void
    {
        $rows = [['productId' => 7, 'storeViewCode' => 'default']];
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->expects(self::once())->method('write')->with($rows);
        $acceptor = $this->createMock(FeedWriteAcceptanceInterface::class);
        $acceptor->expects(self::once())->method('accept')->willThrowException(
            new \RuntimeException('acceptance unavailable'),
        );
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::never())->method('dispatch');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('acceptance unavailable');

        $this->delivery($writer, $events, $cache, $acceptor)
            ->export($rows, $this->metadata('products'));
    }
}
