<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Tax;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Store\Model\Store;
use Magento\Tax\Model\Calculation;

/**
 * Remembers the tax rate and the applied rates per guest rate request under
 * the tax generation. Core keeps them for one request and reads the rules
 * and rates from the database for the next. A signed-in customer's request
 * carries the customer's own address, so it stays with core's per-request
 * cache: the memo holds store-level state only.
 */
class RateMemo
{
    private readonly Memo $rates;

    public function __construct(MemoFactory $memoFactory, private readonly UserContextInterface $userContext)
    {
        $this->rates = $memoFactory->create(['name' => Generation::TAX]);
    }

    public function aroundGetRate(Calculation $subject, \Closure $proceed, $request)
    {
        if ($this->isCustomer()) {
            return $proceed($request);
        }

        return $this->rates->get('rate:' . $this->key($request), static fn() => $proceed($request));
    }

    public function aroundGetAppliedRates(Calculation $subject, \Closure $proceed, $request)
    {
        if ($this->isCustomer()) {
            return $proceed($request);
        }

        return $this->rates->get('applied:' . $this->key($request), static fn() => $proceed($request));
    }

    private function isCustomer(): bool
    {
        return (int)$this->userContext->getUserType() === UserContextInterface::USER_TYPE_CUSTOMER;
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
