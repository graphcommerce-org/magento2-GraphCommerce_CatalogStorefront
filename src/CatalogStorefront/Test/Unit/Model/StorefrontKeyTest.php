<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class StorefrontKeyTest extends TestCase
{
    private function key(string $configured, ?string $sent): StorefrontKey
    {
        $config = $this->createMock(Config::class);
        $config->method('key')->willReturn($configured);
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->with(StorefrontKey::HEADER)->willReturn($sent ?? false);

        return new StorefrontKey($config, $request);
    }

    public function testGrantsOnlyTheConfiguredKey(): void
    {
        self::assertTrue($this->key('abc', 'abc')->granted());
        self::assertFalse($this->key('abc', 'abd')->granted());
        self::assertFalse($this->key('abc', null)->granted());
        self::assertFalse($this->key('', '')->granted());
        self::assertFalse($this->key('', null)->granted());
    }
}
