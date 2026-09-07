<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Model\State;

use Magento\Framework\Search\Request\Config;

/**
 * The search request config with a reload from the cache. A worker that
 * sees a new generation takes the declaration the process that changed an
 * attribute cached; reset() is that process's work, it removes the cache
 * entry and reads the declaration files again.
 */
class SearchRequestConfig extends Config
{
    public function reload(): void
    {
        $this->_data = [];
        $this->initData();
    }
}
