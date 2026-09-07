<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Plugin;

use GraphCommerce\CatalogStorefrontSearch\Model\Config;
use Magento\Search\Model\Search\PageSizeProvider;

/**
 * Core's adapter reads a page beyond this size through a point in time, one
 * window of this size per step, with the aggregations in every window. The
 * configured result window is what the index accepts, so it is the page size.
 */
class ResultWindowPageSize
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function afterGetMaxPageSize(PageSizeProvider $subject, int $result): int
    {
        $window = $this->config->resultWindow();

        return $window > 0 ? $window : $result;
    }
}
