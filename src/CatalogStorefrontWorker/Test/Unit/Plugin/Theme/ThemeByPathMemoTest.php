<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Test\Unit\Plugin\Theme;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use GraphCommerce\CatalogStorefrontWorker\Plugin\Theme\ThemeByPathMemo;
use Magento\Theme\Model\Theme;
use Magento\Theme\Model\Theme\ThemeProvider;
use Magento\Theme\Model\ThemeFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Stub/MemoFactory.php';

class ThemeByPathMemoTest extends TestCase
{
    public function testAPathWithoutAThemeIsAskedOncePerConfigGeneration(): void
    {
        $generation = 'g1';
        $generations = $this->createStub(Generation::class);
        $generations->method('current')->willReturnCallback(static function () use (&$generation): string {
            return $generation;
        });
        $factory = new class ($generations) extends MemoFactory {
            public function __construct(private readonly Generation $generations)
            {
            }

            public function create(array $data = []): Memo
            {
                return new Memo($this->generations, $data['name'], $data['limit'] ?? 5000);
            }
        };
        $empty = $this->createStub(Theme::class);
        $empty->method('getId')->willReturn(null);
        $found = $this->createStub(Theme::class);
        $found->method('getId')->willReturn(4);
        $themes = $this->createStub(ThemeFactory::class);
        $themes->method('create')->willReturnCallback(fn(): Theme => $this->createStub(Theme::class));

        $plugin = new ThemeByPathMemo($factory, $themes);
        $subject = $this->createStub(ThemeProvider::class);
        $calls = [];
        $proceed = static function (string $path) use (&$calls, $empty, $found): Theme {
            $calls[] = $path;

            return $path === 'frontend/Magento/luma' ? $found : $empty;
        };

        $plugin->aroundGetThemeByFullPath($subject, $proceed, 'graphql/_view');
        $plugin->aroundGetThemeByFullPath($subject, $proceed, 'graphql/_view');
        self::assertSame(['graphql/_view'], $calls, 'a path the database holds no theme for is asked once');

        self::assertSame($found, $plugin->aroundGetThemeByFullPath($subject, $proceed, 'frontend/Magento/luma'));
        self::assertSame($found, $plugin->aroundGetThemeByFullPath($subject, $proceed, 'frontend/Magento/luma'));

        $generation = 'g2';
        $plugin->aroundGetThemeByFullPath($subject, $proceed, 'graphql/_view');
        self::assertContains('graphql/_view', array_slice($calls, -1), 'a config generation change asks again');
    }
}
