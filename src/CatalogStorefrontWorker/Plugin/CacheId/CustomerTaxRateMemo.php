<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\CacheId;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use Magento\CustomerGraphQl\CacheIdFactorProviders\CustomerTaxRateProvider;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * Remembers the guest tax rate factor of the response cache id per store and
 * customer group, under the tax generation. Core loads the group, its tax
 * class and the tax rates for every response, on POST requests too. A
 * signed-in customer's factor follows the customer's own address and is
 * computed per request: the memo holds store-level state only.
 */
class CustomerTaxRateMemo
{
    private readonly Memo $factors;

    public function __construct(MemoFactory $memoFactory)
    {
        $this->factors = $memoFactory->create(['name' => Generation::TAX]);
    }

    public function aroundGetFactorValue(CustomerTaxRateProvider $subject, \Closure $proceed, ContextInterface $context): string
    {
        $attributes = $context->getExtensionAttributes();
        if ($attributes->getIsCustomer()) {
            return $proceed($context);
        }
        $key = $attributes->getStore()->getId() . ':' . ($attributes->getCustomerGroupId() ?? 0);

        return $this->factors->get($key, static fn(): string => $proceed($context));
    }
}
