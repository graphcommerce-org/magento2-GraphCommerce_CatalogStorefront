<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Tax;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use Magento\Store\Model\Store;
use Magento\Tax\Model\Calculation;

/**
 * Remembers the tax rate and the applied rates per rate request under the
 * tax generation. Core keeps them for one request and reads the rules and
 * rates from the database for the next.
 */
class RateMemo
{
    private readonly Memo $rates;

    public function __construct(MemoFactory $memoFactory)
    {
        $this->rates = $memoFactory->create(['name' => Generation::TAX]);
    }

    public function aroundGetRate(Calculation $subject, \Closure $proceed, $request)
    {
        return $this->rates->get('rate:' . $this->key($request), static fn() => $proceed($request));
    }

    public function aroundGetAppliedRates(Calculation $subject, \Closure $proceed, $request)
    {
        return $this->rates->get('applied:' . $this->key($request), static fn() => $proceed($request));
    }

    private function key($request): string
    {
        $store = $request->getStore();

        return implode('|', [
            $store instanceof Store ? $store->getId() : (is_numeric($store) ? $store : ''),
            $request->getProductClassId(),
            $request->getCustomerClassId(),
            $request->getCountryId(),
            $request->getRegionId(),
            $request->getPostcode(),
        ]);
    }
}
