<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Strict;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StrictTest extends TestCase
{
    public function testRecordsFallbacksAndStatementsWhenOn(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('strict')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $strict = new Strict($config, $logger);

        $strict->fallback('Vendor\Module\Plugin\Thing', 'document without key');
        $strict->exception('Vendor\Module\Plugin\Other', new \RuntimeException('boom'));
        $strict->statement("SELECT  *\n  FROM a");
        $strict->statement('SELECT * FROM a');
        $strict->statement('SELECT * FROM b');

        self::assertSame([
            'fallbacks' => ['Thing: document without key', 'Other: exception: boom'],
            'sql' => ['SELECT * FROM a' => 2, 'SELECT * FROM b' => 1],
        ], $strict->report());

        $strict->_resetState();
        self::assertSame(['fallbacks' => [], 'sql' => []], $strict->report());
    }

    public function testKeepsNothingWhenOff(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('strict')->willReturn(false);
        $strict = new Strict($config, $this->createMock(LoggerInterface::class));
        $strict->fallback('A', 'b');
        $strict->statement('SELECT 1');
        self::assertSame(['fallbacks' => [], 'sql' => []], $strict->report());
    }
}
