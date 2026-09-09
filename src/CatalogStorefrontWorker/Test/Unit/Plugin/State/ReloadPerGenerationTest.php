<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Test\Unit\Plugin\State;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\State\RequestReload;
use GraphCommerce\CatalogStorefrontWorker\Model\State\SearchRequestConfig;
use GraphCommerce\CatalogStorefrontWorker\Plugin\State\ReloadPerGeneration;
use Magento\Framework\App\State\ReloadProcessorInterface;
use Magento\Framework\App\State\ReloadProcessorComposite;
use Opengento\Application\App\Request\RequestRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReloadPerGenerationTest extends TestCase
{
    public function testTheProcessorsRunOnceUntilTheGenerationChanges(): void
    {
        $generation = 'g1';
        $generations = $this->createStub(Generation::class);
        $generations->method('current')->willReturnCallback(static function () use (&$generation) { return $generation; });
        $requestReload = $this->createMock(RequestReload::class);
        $requestReload->expects($this->exactly(2))->method('reloadState');
        $searchRequestConfig = $this->createMock(SearchRequestConfig::class);
        $searchRequestConfig->expects($this->exactly(2))->method('reload');
        $proceeds = 0;
        $proceed = static function () use (&$proceeds): void {
            $proceeds++;
        };
        $reloadProcessor = $this->createStub(ReloadProcessorInterface::class);
        $plugin = new ReloadPerGeneration($generations, $requestReload, $searchRequestConfig, $reloadProcessor);
        $composite = $this->createStub(ReloadProcessorComposite::class);

        $plugin->aroundReloadState($composite, $proceed);
        $plugin->aroundReloadState($composite, $proceed);
        $plugin->aroundReloadState($composite, $proceed);
        self::assertSame(1, $proceeds);

        $generation = 'g2';
        $plugin->aroundReloadState($composite, $proceed);
        self::assertSame(2, $proceeds);
    }

    public function testChangedConfigReloadsBeforeRequestInitialization(): void
    {
        $generation = 'g1';
        $generations = $this->createStub(Generation::class);
        $generations->method('current')->willReturnCallback(static function () use (&$generation) {
            return $generation;
        });
        $requestReload = $this->createMock(RequestReload::class);
        $requestReload->expects($this->once())->method('reloadState');
        $searchRequestConfig = $this->createMock(SearchRequestConfig::class);
        $searchRequestConfig->expects($this->exactly(2))->method('reload');
        $reloadProcessor = $this->createMock(ReloadProcessorInterface::class);
        $reloadProcessor->expects($this->exactly(2))->method('reloadState')
            ->willReturnCallback(function () use (&$plugin, &$proceeds, &$composite): void {
                $plugin->aroundReloadState($composite, static function () use (&$proceeds): void {
                    $proceeds++;
                });
            });
        $plugin = new ReloadPerGeneration(
            $generations,
            $requestReload,
            $searchRequestConfig,
            $reloadProcessor,
        );
        $proceeds = 0;
        $composite = $this->createStub(ReloadProcessorComposite::class);
        $registry = $this->createStub(RequestRegistry::class);

        $plugin->beforeInitFromSuperGlobals($registry);
        self::assertSame(1, $proceeds);
        $plugin->beforeInitFromSuperGlobals($registry);
        self::assertSame(1, $proceeds, 'unchanged config does not reload per request');
        $plugin->aroundReloadState($composite, static function (): void {
        });

        $generation = 'g2';
        $plugin->beforeInitFromSuperGlobals($registry);
        self::assertSame(2, $proceeds, 'changed config reloads before the request is registered');
    }

    #[DataProvider('failedReloadProvider')]
    public function testFailedReloadIsRetriedOnTheNextRequest(string $failure): void
    {
        $generations = $this->createStub(Generation::class);
        $generations->method('current')->willReturn('g1');
        $requestReload = $this->createStub(RequestReload::class);
        $searchRequestConfig = $this->createStub(SearchRequestConfig::class);
        $searchCalls = 0;
        $searchRequestConfig->method('reload')
            ->willReturnCallback(static function () use ($failure, &$searchCalls): void {
                $searchCalls++;
                if ($failure === 'search' && $searchCalls === 1) {
                    throw new \RuntimeException('search reload failed');
                }
            });
        $proceedCalls = 0;
        $reloadProcessor = $this->createMock(ReloadProcessorInterface::class);
        $reloadProcessor->expects($this->exactly(2))->method('reloadState')
            ->willReturnCallback(function () use ($failure, &$plugin, &$proceedCalls, &$composite): void {
                $plugin->aroundReloadState(
                    $composite,
                    static function () use ($failure, &$proceedCalls): void {
                        $proceedCalls++;
                        if ($failure === 'composite' && $proceedCalls === 1) {
                            throw new \RuntimeException('composite reload failed');
                        }
                    },
                );
            });
        $plugin = new ReloadPerGeneration(
            $generations,
            $requestReload,
            $searchRequestConfig,
            $reloadProcessor,
        );
        $composite = $this->createStub(ReloadProcessorComposite::class);
        $registry = $this->createStub(RequestRegistry::class);

        try {
            $plugin->beforeInitFromSuperGlobals($registry);
            self::fail('The failed reload did not propagate its exception');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('reload failed', $exception->getMessage());
        }
        $plugin->beforeInitFromSuperGlobals($registry);

        self::assertSame(2, $searchCalls);
        self::assertSame($failure === 'search' ? 1 : 2, $proceedCalls);
    }

    public static function failedReloadProvider(): array
    {
        return [
            'search config reload' => ['search'],
            'composite reload' => ['composite'],
        ];
    }
}
