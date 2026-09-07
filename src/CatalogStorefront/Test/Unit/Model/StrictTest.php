<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StrictTest extends TestCase
{
    public function testRecordsFallbacksWhenOn(): void
    {
        $config = $this->createMock(StorefrontKey::class);
        $config->method('granted')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $strict = new Strict($config, $logger);

        $strict->fallback('Vendor\Module\Plugin\Thing', 'document without key');
        $strict->exception('Vendor\Module\Plugin\Other', new \RuntimeException('boom'));

        self::assertSame(
            ['fallbacks' => ['Thing: document without key', 'Other: exception: boom']],
            $strict->report()
        );

        $strict->_resetState();
        self::assertSame(['fallbacks' => []], $strict->report());
    }

    public function testKeepsNothingWhenOff(): void
    {
        $config = $this->createMock(StorefrontKey::class);
        $config->method('granted')->willReturn(false);
        $strict = new Strict($config, $this->createMock(LoggerInterface::class));
        $strict->fallback('A', 'b');
        self::assertSame(['fallbacks' => []], $strict->report());
    }
}
