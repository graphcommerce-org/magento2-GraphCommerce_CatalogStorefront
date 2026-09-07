<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\State;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\State\RequestReload;
use GraphCommerce\CatalogStorefrontWorker\Model\State\SearchRequestConfig;
use Magento\Framework\App\State\ReloadProcessorComposite;

/**
 * Runs the reload processors once per config generation. The stores, the
 * system config and the search request config a worker holds are what the
 * cache holds until a config cache clean or a search request reset lifts
 * the generation, so between two generations only the request's own
 * processor runs.
 */
class ReloadPerGeneration
{
    private ?string $reloaded = null;

    public function __construct(
        private readonly Generation $generations,
        private readonly RequestReload $requestReload,
        private readonly SearchRequestConfig $searchRequestConfig,
    ) {
    }

    public function aroundReloadState(ReloadProcessorComposite $subject, \Closure $proceed): void
    {
        $generation = $this->generations->current(Generation::CONFIG);
        if ($generation === $this->reloaded) {
            $this->requestReload->reloadState();

            return;
        }
        $this->reloaded = $generation;
        $this->searchRequestConfig->reload();
        $proceed();
    }
}
