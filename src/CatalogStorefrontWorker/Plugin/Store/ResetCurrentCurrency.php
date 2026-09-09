<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Store;

use Magento\Directory\Model\Currency;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\Store;
use WeakMap;

/**
 * Discards request currency state from every retained store after a request.
 */
class ResetCurrentCurrency implements ResetAfterRequestInterface
{
    /** @var WeakMap<Store, bool> */
    private WeakMap $stores;

    public function __construct()
    {
        $this->stores = new WeakMap();
    }

    public function afterGetCurrentCurrency(Store $subject, Currency $result): Currency
    {
        $this->stores[$subject] = true;

        return $result;
    }

    public function _resetState(): void
    {
        foreach ($this->stores as $store => $_) {
            // Store::_resetState keeps this DataObject entry even though its
            // code comes from the request's HttpContext.
            $store->unsetData('current_currency');
        }
        $this->stores = new WeakMap();
    }
}
