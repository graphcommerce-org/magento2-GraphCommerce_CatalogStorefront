<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StrictTest extends TestCase
{
    public function testEveryFallbackThrowsAndOnlyKeyedRequestsKeepAReport(): void
    {
        foreach ([false, true] as $keyed) {
            $key = $this->createStub(StorefrontKey::class);
            $key->method('granted')->willReturn($keyed);
            $strict = new Strict($key, $this->createStub(LoggerInterface::class));
            try {
                $strict->fallback('Vendor\\Module\\Plugin\\Thing', 'document without key');
                self::fail('The document read must throw.');
            } catch (DocumentReadException $error) {
                self::assertSame('Catalog document read failed in Thing: document without key', $error->getMessage());
            }
            self::assertSame(['fallbacks' => $keyed ? ['Thing: document without key'] : []], $strict->report());
            $strict->_resetState();
            self::assertSame(['fallbacks' => []], $strict->report());
        }
    }

    public function testStorageFailurePreservesTheCause(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $strict = new Strict($this->createStub(StorefrontKey::class), $logger);
        $cause = new \RuntimeException('cluster unavailable');
        try {
            $strict->exception('Reader', $cause);
            self::fail('The storage failure must throw.');
        } catch (DocumentReadException $error) {
            self::assertSame($cause, $error->getPrevious());
            self::assertSame('Catalog document read failed in Reader: cluster unavailable', $error->getMessage());
        }
        self::assertSame(['fallbacks' => []], $strict->report());
    }

    public function testAnOuterReaderPreservesTheDocumentFailure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $strict = new Strict($this->createStub(StorefrontKey::class), $logger);
        $failure = new DocumentReadException('Missing product document.');
        try {
            $strict->exception('OuterReader', $failure);
            self::fail('The document failure must throw.');
        } catch (DocumentReadException $error) {
            self::assertSame($failure, $error);
        }
    }
}
