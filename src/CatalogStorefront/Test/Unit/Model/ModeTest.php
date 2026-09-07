<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Mode;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class ModeTest extends TestCase
{
    private function mode(bool $serve, bool $override, ?string $header): Mode
    {
        $config = $this->createMock(Config::class);
        $config->method('serveGraphQl')->willReturn($serve);
        $key = $this->createMock(StorefrontKey::class);
        $key->method('granted')->willReturn($override);
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->with(Mode::HEADER)->willReturn($header ?? false);

        return new Mode($config, $key, $request);
    }

    public function testTheHeaderCountsOnlyWithTheKey(): void
    {
        self::assertTrue($this->mode(true, false, 'core')->documents());
        self::assertFalse($this->mode(true, true, 'core')->documents());
        self::assertTrue($this->mode(false, true, 'Documents')->documents());
        self::assertFalse($this->mode(false, true, 'other')->documents());
        self::assertSame('documents', $this->mode(false, true, 'documents')->name());
        self::assertSame('core', $this->mode(false, false, null)->name());
    }
}
