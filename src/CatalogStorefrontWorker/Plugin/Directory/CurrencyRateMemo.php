<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Directory;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use Magento\Directory\Model\Currency as CurrencyModel;
use Magento\Directory\Model\ResourceModel\Currency;

/**
 * Remembers a currency rate under the currency generation: the store reads
 * the rate to its display currency from the database on every request that
 * converts a price.
 */
class CurrencyRateMemo
{
    private readonly Memo $rates;

    public function __construct(MemoFactory $memoFactory)
    {
        $this->rates = $memoFactory->create(['name' => Generation::CURRENCY]);
    }

    public function aroundGetRate(Currency $subject, \Closure $proceed, $currencyFrom, $currencyTo)
    {
        return $this->rates->get(
            'rate:' . $this->code($currencyFrom) . ':' . $this->code($currencyTo),
            static fn() => $proceed($currencyFrom, $currencyTo)
        );
    }

    public function aroundGetAnyRate(Currency $subject, \Closure $proceed, $currencyFrom, $currencyTo)
    {
        return $this->rates->get(
            'any:' . $this->code($currencyFrom) . ':' . $this->code($currencyTo),
            static fn() => $proceed($currencyFrom, $currencyTo)
        );
    }

    private function code($currency): string
    {
        return $currency instanceof CurrencyModel ? (string)$currency->getCode() : (string)$currency;
    }
}
