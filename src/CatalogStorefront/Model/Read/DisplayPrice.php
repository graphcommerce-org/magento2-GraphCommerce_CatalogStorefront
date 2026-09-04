<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Weee\Helper\Data as WeeeHelper;

/**
 * Turns a base currency price before tax, as the documents hold it, into the
 * amount core's price info yields for the request: converted to the store's
 * display currency the way the price classes convert (the regular price
 * unrounded, a discounted price rounded), then taxed the way the tax
 * adjustment taxes (the price including tax whenever catalog prices include
 * tax or the display does; core's tax service answers for the request's
 * customer and destination). Fixed product taxes are not answered.
 */
class DisplayPrice
{
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TaxHelper $taxHelper,
        private readonly CatalogHelper $catalogHelper,
        private readonly WeeeHelper $weeeHelper,
        private readonly StockConfigurationInterface $stockConfiguration,
    ) {
    }

    public function servable(StoreInterface $store): bool
    {
        return !$this->weeeHelper->isEnabled($store);
    }

    public function showOutOfStock(StoreInterface $store): bool
    {
        return $this->stockConfiguration->isShowOutOfStock((int)$store->getId());
    }

    /**
     * The display amount of a regular price, or of a final price that equals it.
     */
    public function regular(float $base, Product $product, StoreInterface $store): float
    {
        return $this->withTax((float)$this->priceCurrency->convert($base, $store), $product, $store);
    }

    /**
     * The display amount of a final price: rounded after conversion when it
     * is lower than the regular price, as the special, tier and rule prices
     * are, the regular amount otherwise.
     */
    public function final(float $base, float $regularBase, Product $product, StoreInterface $store): float
    {
        return $base < $regularBase
            ? $this->withTax((float)$this->priceCurrency->convertAndRound($base, $store), $product, $store)
            : $this->regular($regularBase, $product, $store);
    }

    /**
     * The tax the display amount carries: the difference to the amount
     * without tax, zero when no tax applies.
     */
    public function taxAmount(float $displayAmount, Product $product, StoreInterface $store): float
    {
        if ($this->taxHelper->priceIncludesTax($store)) {
            return $displayAmount - (float)$this->catalogHelper->getTaxPrice($product, $displayAmount, false, null, null, null, $store, null, false);
        }

        return $this->taxIncluded($store) ? $displayAmount - $this->withoutTax($displayAmount, $product, $store) : 0.0;
    }

    public function taxIncluded(StoreInterface $store): bool
    {
        return $this->taxHelper->displayPriceIncludingTax($store) || $this->taxHelper->displayBothPrices($store);
    }

    private function withTax(float $amount, Product $product, StoreInterface $store): float
    {
        if (!$this->taxHelper->priceIncludesTax($store) && !$this->taxIncluded($store)) {
            return $amount;
        }

        return (float)$this->catalogHelper->getTaxPrice($product, $amount, true, null, null, null, $store, null, false);
    }

    private function withoutTax(float $displayAmount, Product $product, StoreInterface $store): float
    {
        return (float)$this->catalogHelper->getTaxPrice($product, $displayAmount, false, null, null, null, $store, true, false);
    }
}
