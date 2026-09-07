<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Model\State;

use Magento\Framework\App\State\ReloadProcessorInterface;
use Opengento\Application\App\Session\SessionRegistry;

/**
 * The part of the reload after a response that belongs to the request: its
 * sessions close. The store, config and search request state is held under
 * the config generation by ReloadPerGeneration, and the EAV attributes stay
 * as core's own reset keeps them: the attribute objects with their store
 * labels, options and source state reset, so no request loads an attribute
 * from the cache again (32 loads, 6 ms, on a listing with every filter).
 */
class RequestReload implements ReloadProcessorInterface
{
    public function __construct(
        private readonly SessionRegistry $sessionRegistry,
    ) {
    }

    public function reloadState(): void
    {
        $this->sessionRegistry->closeSessions();
    }
}
