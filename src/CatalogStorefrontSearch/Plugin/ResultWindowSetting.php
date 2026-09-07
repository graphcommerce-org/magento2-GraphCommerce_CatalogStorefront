<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Plugin;

use GraphCommerce\CatalogStorefrontSearch\Model\Config;
use Magento\Elasticsearch\Model\Adapter\Index\Builder;

/**
 * A product search index is created with the configured result window, so a
 * listing page within it is one query. `ResultWindowPageSize` tells core's
 * adapter the same number, and the setting's backend model puts it on the
 * indices that exist.
 */
class ResultWindowSetting
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function afterBuild(Builder $subject, array $result): array
    {
        $window = $this->config->resultWindow();
        if ($window > 0) {
            $result['max_result_window'] = $window;
        }

        return $result;
    }
}
