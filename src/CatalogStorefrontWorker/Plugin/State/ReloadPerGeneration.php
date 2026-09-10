<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\State;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\State\RequestDecisions;
use GraphCommerce\CatalogStorefrontWorker\Model\State\RequestReload;
use GraphCommerce\CatalogStorefrontWorker\Model\State\SearchRequestConfig;
use Magento\Framework\App\State\ReloadProcessorInterface;
use Magento\Framework\App\State\ReloadProcessorComposite;
use Opengento\Application\App\Request\RequestRegistry;

/**
 * Runs the reload processors before the first request of a config generation.
 * The post-response call still closes request sessions, but it does not defer
 * a changed store or system config until after one stale response. Decisions
 * that may have been read before request registration are cleared afterwards,
 * so they use the current headers and the reloaded config.
 */
class ReloadPerGeneration
{
    private ?string $reloaded = null;

    public function __construct(
        private readonly Generation $generations,
        private readonly RequestReload $requestReload,
        private readonly SearchRequestConfig $searchRequestConfig,
        private readonly ReloadProcessorInterface $reloadProcessor,
        private readonly RequestDecisions $requestDecisions,
    ) {
    }

    public function beforeInitFromSuperGlobals(RequestRegistry $subject): void
    {
        if ($this->generations->current(Generation::CONFIG) !== $this->reloaded) {
            $this->reloadProcessor->reloadState();
        }
    }

    public function afterInitFromSuperGlobals(RequestRegistry $subject): void
    {
        $this->requestDecisions->reset();
    }

    public function aroundReloadState(ReloadProcessorComposite $subject, \Closure $proceed): void
    {
        $generation = $this->generations->current(Generation::CONFIG);
        if ($generation === $this->reloaded) {
            $this->requestReload->reloadState();

            return;
        }
        $this->searchRequestConfig->reload();
        $proceed();
        $this->reloaded = $generation;
    }
}
