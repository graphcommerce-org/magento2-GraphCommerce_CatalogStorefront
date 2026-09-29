<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin;

use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\PageCache\Model\Config;

class KeyedPageUncacheable
{
    public function __construct(private readonly Mode $mode)
    {
    }

    public function afterIsEnabled(Config $subject, bool $result): bool
    {
        return $result && $this->mode->requested() === null;
    }
}
