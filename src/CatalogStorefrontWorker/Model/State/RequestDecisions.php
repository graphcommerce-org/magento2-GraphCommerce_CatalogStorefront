<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Model\State;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Clears request decisions after the current headers have been registered.
 */
class RequestDecisions
{
    public function __construct(
        private readonly StorefrontKey $storefrontKey,
        private readonly Mode $mode,
        private readonly Strict $strict,
    ) {
    }

    public function reset(): void
    {
        $this->storefrontKey->_resetState();
        $this->mode->_resetState();
        $this->strict->_resetState();
    }
}
