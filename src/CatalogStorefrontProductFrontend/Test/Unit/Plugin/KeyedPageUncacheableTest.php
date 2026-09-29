<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\KeyedPageUncacheable;
use Magento\PageCache\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KeyedPageUncacheableTest extends TestCase
{
    #[DataProvider('requests')]
    public function testOnlyOrdinaryRequestsUsePageCache(?string $requested, bool $enabled, bool $expected): void
    {
        $mode = $this->createStub(Mode::class);
        $mode->method('requested')->willReturn($requested);
        $plugin = new KeyedPageUncacheable($mode);

        self::assertSame($expected, $plugin->afterIsEnabled($this->createStub(Config::class), $enabled));
    }

    public static function requests(): iterable
    {
        yield 'ordinary enabled' => [null, true, true];
        yield 'ordinary disabled' => [null, false, false];
        yield 'keyed core' => [Mode::CORE, true, false];
        yield 'keyed documents' => [Mode::DOCUMENTS, true, false];
        yield 'keyed disabled' => [Mode::DOCUMENTS, false, false];
    }
}
