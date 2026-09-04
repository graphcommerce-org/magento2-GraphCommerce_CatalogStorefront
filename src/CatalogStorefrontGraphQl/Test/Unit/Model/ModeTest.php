<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Mode;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class ModeTest extends TestCase
{
    private function mode(bool $serve, bool $override, ?string $header): Mode
    {
        $config = $this->createMock(Config::class);
        $config->method('serveGraphQl')->willReturn($serve);
        $config->method('requestOverride')->willReturn($override);
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->with(Mode::HEADER)->willReturn($header ?? false);

        return new Mode($config, $request);
    }

    public function testTheHeaderCountsOnlyWithOverridesAllowed(): void
    {
        self::assertTrue($this->mode(true, false, 'core')->documents());
        self::assertFalse($this->mode(true, true, 'core')->documents());
        self::assertTrue($this->mode(false, true, 'Documents')->documents());
        self::assertFalse($this->mode(false, true, 'other')->documents());
        self::assertSame('documents', $this->mode(false, true, 'documents')->name());
        self::assertSame('core', $this->mode(false, false, null)->name());
    }
}
