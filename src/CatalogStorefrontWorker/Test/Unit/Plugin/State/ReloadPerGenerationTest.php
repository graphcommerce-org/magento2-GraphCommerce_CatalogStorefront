<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Test\Unit\Plugin\State;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\State\RequestReload;
use GraphCommerce\CatalogStorefrontWorker\Model\State\SearchRequestConfig;
use GraphCommerce\CatalogStorefrontWorker\Plugin\State\ReloadPerGeneration;
use Magento\Framework\App\State\ReloadProcessorComposite;
use PHPUnit\Framework\TestCase;

class ReloadPerGenerationTest extends TestCase
{
    public function testTheProcessorsRunOnceUntilTheGenerationChanges(): void
    {
        $generation = 'g1';
        $generations = $this->createMock(Generation::class);
        $generations->method('current')->willReturnCallback(static function () use (&$generation) { return $generation; });
        $requestReload = $this->createMock(RequestReload::class);
        $requestReload->expects($this->exactly(2))->method('reloadState');
        $searchRequestConfig = $this->createMock(SearchRequestConfig::class);
        $searchRequestConfig->expects($this->exactly(2))->method('reload');
        $proceeds = 0;
        $proceed = static function () use (&$proceeds): void {
            $proceeds++;
        };
        $plugin = new ReloadPerGeneration($generations, $requestReload, $searchRequestConfig);
        $composite = $this->createMock(ReloadProcessorComposite::class);

        $plugin->aroundReloadState($composite, $proceed);
        $plugin->aroundReloadState($composite, $proceed);
        $plugin->aroundReloadState($composite, $proceed);
        self::assertSame(1, $proceeds);

        $generation = 'g2';
        $plugin->aroundReloadState($composite, $proceed);
        self::assertSame(2, $proceeds);
    }
}
