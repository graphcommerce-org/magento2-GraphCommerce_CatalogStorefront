<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Weee\Helper\Data as WeeeHelper;

/**
 * The price display setup a document can answer: the display currency is the
 * base currency, catalog prices exclude tax and are displayed excluding tax,
 * and fixed product taxes are off. Other setups keep the core price path.
 */
class PriceDisplay
{
    public function __construct(
        private readonly TaxConfig $taxConfig,
        private readonly WeeeHelper $weeeHelper,
        private readonly StockConfigurationInterface $stockConfiguration,
    ) {
    }

    public function servable(StoreInterface $store): bool
    {
        return $store->getCurrentCurrencyCode() === $store->getBaseCurrencyCode()
            && !$this->taxConfig->priceIncludesTax($store)
            && (int)$this->taxConfig->getPriceDisplayType($store) === TaxConfig::DISPLAY_TYPE_EXCLUDING_TAX
            && !$this->weeeHelper->isEnabled($store);
    }

    public function showOutOfStock(StoreInterface $store): bool
    {
        return $this->stockConfiguration->isShowOutOfStock((int)$store->getId());
    }
}
