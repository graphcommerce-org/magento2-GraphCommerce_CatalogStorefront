<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Test\Unit\Plugin\State;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\State\RequestDecisions;
use GraphCommerce\CatalogStorefrontWorker\Model\State\RequestReload;
use GraphCommerce\CatalogStorefrontWorker\Model\State\SearchRequestConfig;
use GraphCommerce\CatalogStorefrontWorker\Plugin\State\ReloadPerGeneration;
use Magento\Framework\App\State\ReloadProcessorInterface;
use Magento\Framework\App\State\ReloadProcessorComposite;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Opengento\Application\App\Request\RequestRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
        $plugin = $this->createPlugin($generations, $requestReload, $searchRequestConfig, $reloadProcessor);
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
        $plugin = $this->createPlugin($generations, $requestReload, $searchRequestConfig, $reloadProcessor);
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
        $plugin = $this->createPlugin($generations, $requestReload, $searchRequestConfig, $reloadProcessor);
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

    public function testCurrentHeadersAndReloadedKeyReplaceADecisionLatchedDuringPreflight(): void
    {
        $configuredKey = 'old-key';
        $keyHeader = '';
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use (&$configuredKey): ?string {
                return $path === Config::KEY ? $configuredKey : null;
            },
        );
        $request = $this->createStub(Http::class);
        $request->method('getHeader')->willReturnCallback(
            static function (string $name) use (&$keyHeader): string {
                return $name === StorefrontKey::HEADER ? $keyHeader : '';
            },
        );
        $config = new Config($scopeConfig);
        $storefrontKey = new StorefrontKey($config, $request);
        $mode = new Mode($config, $storefrontKey, $request);
        $strict = new Strict($storefrontKey, $this->createStub(LoggerInterface::class));
        $generations = $this->createStub(Generation::class);
        $generations->method('current')->willReturn('g1');
        $requestReload = $this->createStub(RequestReload::class);
        $searchRequestConfig = $this->createStub(SearchRequestConfig::class);
        $composite = $this->createStub(ReloadProcessorComposite::class);
        $reloadProcessor = $this->createMock(ReloadProcessorInterface::class);
        $reloadProcessor->expects($this->once())->method('reloadState')
            ->willReturnCallback(function () use (&$plugin, $composite, $strict, &$configuredKey): void {
                self::assertFalse($strict->enabled(), 'reload SQL can read the decision before headers are registered');
                $plugin->aroundReloadState($composite, static function () use (&$configuredKey): void {
                    $configuredKey = 'new-key';
                });
            });
        $plugin = new ReloadPerGeneration(
            $generations,
            $requestReload,
            $searchRequestConfig,
            $reloadProcessor,
            new RequestDecisions($storefrontKey, $mode, $strict),
        );
        $registry = $this->createStub(RequestRegistry::class);

        $plugin->beforeInitFromSuperGlobals($registry);
        $keyHeader = 'new-key';
        $plugin->afterInitFromSuperGlobals($registry);
        self::assertTrue($strict->enabled(), 'the current header is checked against config loaded by the preflight');
        $strict->fallback(self::class, 'proof');

        $keyHeader = 'old-key';
        $plugin->afterInitFromSuperGlobals($registry);
        self::assertFalse($strict->enabled(), 'a key replaced in config is revoked on the next request');
        self::assertSame(['fallbacks' => []], $strict->report());
    }

    public function testDefaultDocumentDecisionIsReevaluatedAfterRequestRegistration(): void
    {
        $serveDocuments = false;
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('');
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path) use (&$serveDocuments): bool {
                return $path === Config::SERVE_GRAPHQL && $serveDocuments;
            },
        );
        $request = $this->createStub(Http::class);
        $request->method('getHeader')->willReturn('');
        $config = new Config($scopeConfig);
        $storefrontKey = new StorefrontKey($config, $request);
        $mode = new Mode($config, $storefrontKey, $request);
        $strict = new Strict($storefrontKey, $this->createStub(LoggerInterface::class));
        $plugin = new ReloadPerGeneration(
            $this->createStub(Generation::class),
            $this->createStub(RequestReload::class),
            $this->createStub(SearchRequestConfig::class),
            $this->createStub(ReloadProcessorInterface::class),
            new RequestDecisions($storefrontKey, $mode, $strict),
        );
        $registry = $this->createStub(RequestRegistry::class);

        self::assertFalse($mode->documents());
        $serveDocuments = true;
        self::assertFalse($mode->documents(), 'the process memo still holds the earlier config decision');

        $plugin->afterInitFromSuperGlobals($registry);
        self::assertTrue($mode->documents(), 'the first request after a config reload uses its new default path');
    }

    private function createPlugin(
        Generation $generations,
        RequestReload $requestReload,
        SearchRequestConfig $searchRequestConfig,
        ReloadProcessorInterface $reloadProcessor,
    ): ReloadPerGeneration {
        return new ReloadPerGeneration(
            $generations,
            $requestReload,
            $searchRequestConfig,
            $reloadProcessor,
            $this->createStub(RequestDecisions::class),
        );
    }
}
