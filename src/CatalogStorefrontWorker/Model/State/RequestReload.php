<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Model\State;

use Magento\Framework\App\State\ReloadProcessorInterface;
use Opengento\Application\App\Session\SessionRegistry;
use Opengento\Application\Model\EavConfig;

/**
 * The part of the reload after a response that belongs to the request: its
 * sessions close and the EAV runtime cache empties. The store, config and
 * search request state is held under the config generation by
 * ReloadPerGeneration.
 */
class RequestReload implements ReloadProcessorInterface
{
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly SessionRegistry $sessionRegistry,
    ) {
    }

    public function reloadState(): void
    {
        $this->eavConfig->clearRuntimeCache();
        $this->sessionRegistry->closeSessions();
    }
}
