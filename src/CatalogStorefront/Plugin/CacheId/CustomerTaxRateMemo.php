<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\CacheId;

use Magento\CustomerGraphQl\CacheIdFactorProviders\CustomerTaxRateProvider;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * Remembers the customer tax rate factor of the response cache id per store,
 * customer group and customer per process. Core loads the group, its tax
 * class and the tax rates for every response, on POST requests too. The
 * factor derives from tax configuration and the customer's addresses; a
 * change reaches a worker at its next restart.
 */
class CustomerTaxRateMemo
{
    private const LIMIT = 5000;

    /** @var array<string, string> */
    private array $factors = [];

    public function aroundGetFactorValue(CustomerTaxRateProvider $subject, \Closure $proceed, ContextInterface $context): string
    {
        $attributes = $context->getExtensionAttributes();
        $key = $attributes->getStore()->getId() . ':' . ($attributes->getCustomerGroupId() ?? 0) . ':'
            . ($attributes->getIsCustomer() ? (int)$context->getUserId() : 0);
        if (!isset($this->factors[$key])) {
            if (count($this->factors) >= self::LIMIT) {
                $this->factors = [];
            }
            $this->factors[$key] = $proceed($context);
        }

        return $this->factors[$key];
    }
}
