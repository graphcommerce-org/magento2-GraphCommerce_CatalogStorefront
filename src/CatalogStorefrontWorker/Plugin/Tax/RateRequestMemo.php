<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Tax;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use Magento\Framework\DataObject;
use Magento\Tax\Model\Calculation;

/**
 * Remembers the tax rate request core builds for a store and a customer
 * without explicit addresses, under the tax generation. Core loads the
 * customer, the default addresses and the group's tax class for it on every
 * catalog price with tax. A request built for explicit address objects (the
 * cart) is not kept. Callers set the product class on the returned object,
 * so each gets a copy.
 */
class RateRequestMemo
{
    private readonly Memo $requests;

    public function __construct(MemoFactory $memoFactory)
    {
        $this->requests = $memoFactory->create(['name' => Generation::TAX]);
    }

    public function aroundGetRateRequest(
        Calculation $subject,
        \Closure $proceed,
        $shippingAddress = null,
        $billingAddress = null,
        $customerTaxClass = null,
        $store = null,
        $customerId = null
    ) {
        if (is_object($shippingAddress) || is_object($billingAddress) || is_object($customerTaxClass) || is_object($store)) {
            return $proceed($shippingAddress, $billingAddress, $customerTaxClass, $store, $customerId);
        }
        $key = json_encode([$shippingAddress, $billingAddress, $customerTaxClass, $store, $customerId]);
        $request = $this->requests->get(
            $key,
            static fn(): DataObject => $proceed($shippingAddress, $billingAddress, $customerTaxClass, $store, $customerId)
        );

        return clone $request;
    }
}
