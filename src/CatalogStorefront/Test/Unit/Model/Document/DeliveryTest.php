<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document;

use GraphCommerce\CatalogStorefront\Model\Document\Delivery;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use Magento\DataExporter\Model\FeedExportStatus;
use Magento\DataExporter\Model\FeedExportStatusBuilder;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\CacheContextFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeliveryTest extends TestCase
{
    private const DOCUMENTS_FLAG = 'catalog/storefront_documents/index_enabled';
    private const SOURCE_FLAG = 'catalog/other_package/writers_enabled';

    private ?int $statusCode = null;

    /**
     * @param FeedWriterInterface[] $writers
     * @param string[] $off the configuration paths that answer false
     */
    private function delivery(
        array $writers,
        ManagerInterface $events,
        CacheInterface $cache,
        array $off = [],
    ): Delivery {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn(string $path): bool => !in_array($path, $off, true),
        );
        $statusBuilder = $this->createMock(FeedExportStatusBuilder::class);
        $statusBuilder->method('build')->willReturnCallback(function (int $code) {
            $this->statusCode = $code;

            return $this->createMock(FeedExportStatus::class);
        });
        $contextFactory = $this->createMock(CacheContextFactory::class);
        $contextFactory->method('create')->willReturnCallback(static fn() => new CacheContext());

        return new Delivery(
            $statusBuilder,
            $scopeConfig,
            $this->createMock(LoggerInterface::class),
            $contextFactory,
            $events,
            $cache,
            ['products' => $writers],
            ['products' => ['cat_p' => ['productId', 'parentId']]],
            ['documents' => self::DOCUMENTS_FLAG, 'source' => self::SOURCE_FLAG],
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

        $this->delivery(['documents' => $writer], $events, $cache)->export($rows, $this->metadata('products'));
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

        $this->delivery(['documents' => $writer], $events, $cache)
            ->export([['productId' => 1]], $this->metadata('orders'));
    }

    public function testAFailedWriteAsksForARetryAndPurgesNothing(): void
    {
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->method('write')->willThrowException(new \RuntimeException('down'));
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $this->delivery(['documents' => $writer], $this->createMock(ManagerInterface::class), $cache)
            ->export([['productId' => 1]], $this->metadata('products'));
        self::assertSame(500, $this->statusCode);
    }

    public function testAFeedWhoseWritersAreAllOffSkipsThePurge(): void
    {
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->expects(self::never())->method('write');
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::never())->method('dispatch');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $this->delivery(['documents' => $writer], $events, $cache, [self::DOCUMENTS_FLAG])
            ->export([['productId' => 1]], $this->metadata('products'));

        self::assertSame(200, $this->statusCode);
    }

    public function testOneWriterFamilyOffLeavesTheOtherWriting(): void
    {
        $rows = [['productId' => 7, 'storeViewCode' => 'default']];
        $documents = $this->createMock(FeedWriterInterface::class);
        $documents->expects(self::never())->method('write');
        $source = $this->createMock(FeedWriterInterface::class);
        $source->expects(self::once())->method('write')->with($rows);
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::once())->method('dispatch');

        $this->delivery(
            ['documents' => $documents, 'source' => $source],
            $events,
            $this->createMock(CacheInterface::class),
            [self::DOCUMENTS_FLAG],
        )->export($rows, $this->metadata('products'));

        self::assertSame(200, $this->statusCode);
    }

    public function testAWriterWithoutAFlagWritesWhileEveryFlagIsOff(): void
    {
        $rows = [['productId' => 7, 'storeViewCode' => 'default']];
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->expects(self::once())->method('write')->with($rows);

        $this->delivery(
            ['unflagged' => $writer],
            $this->createMock(ManagerInterface::class),
            $this->createMock(CacheInterface::class),
            [self::DOCUMENTS_FLAG, self::SOURCE_FLAG],
        )->export($rows, $this->metadata('products'));

        self::assertSame(200, $this->statusCode);
    }

    public function testEveryWriterOfTheFeedGetsTheBatchInRegistrationOrder(): void
    {
        $rows = [['productId' => 7, 'storeViewCode' => 'default']];
        $order = [];
        $writers = [];
        foreach (['documents', 'source'] as $name) {
            $writer = $this->createMock(FeedWriterInterface::class);
            $writer->expects(self::once())->method('write')->with($rows)->willReturnCallback(
                static function () use (&$order, $name): void {
                    $order[] = $name;
                },
            );
            $writers[$name] = $writer;
        }
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::once())->method('dispatch')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'purge';
            },
        );

        $this->delivery($writers, $events, $this->createMock(CacheInterface::class))
            ->export($rows, $this->metadata('products'));

        self::assertSame(['documents', 'source', 'purge'], $order);
        self::assertSame(200, $this->statusCode);
    }

    public function testASecondWriterThatFailsAsksForARetryAndPurgesNothing(): void
    {
        $first = $this->createMock(FeedWriterInterface::class);
        $first->expects(self::once())->method('write');
        $second = $this->createMock(FeedWriterInterface::class);
        $second->method('write')->willThrowException(new \RuntimeException('down'));
        $events = $this->createMock(ManagerInterface::class);
        $events->expects(self::never())->method('dispatch');
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('clean');

        $this->delivery(['documents' => $first, 'source' => $second], $events, $cache)
            ->export([['productId' => 1]], $this->metadata('products'));

        self::assertSame(500, $this->statusCode);
    }
}
