<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPrice\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\Amount;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Helper\Data as TaxHelper;

/**
 * Turns a base currency price before tax, as the documents hold it, into the
 * amount core's price info yields for the request: converted to the store's
 * display currency the way the price classes convert (the regular price
 * unrounded, a discounted price rounded), then taxed the way the tax
 * adjustment taxes (the price including tax whenever catalog prices include
 * tax or the display does; core's tax service answers for the request's
 * customer and destination with the product's tax class), then raised by
 * the product's fixed product taxes the way the weee adjustments raise it.
 */
class DisplayPrice
{
    public function __construct(
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly TaxHelper $taxHelper,
        private readonly CatalogHelper $catalogHelper,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly FixedProductTax $fixedProductTax,
    ) {
    }

    public function showOutOfStock(StoreInterface $store): bool
    {
        return $this->stockConfiguration->isShowOutOfStock((int)$store->getId());
    }

    /**
     * The display amount of a base price: rounded after conversion when it is
     * a discounted price, to the decimals of its source, as core rounds a
     * special or tier price to two and a catalog rule price to four.
     *
     * @param array[] $fixedProductTaxes the document's fixedProductTaxes rows
     */
    public function amount(float $base, bool $discounted, Product $product, StoreInterface $store, int $precision = 2, array $fixedProductTaxes = []): Amount
    {
        $converted = $discounted
            ? (float)$this->priceCurrency->convertAndRound($base, $store, null, $precision)
            : (float)$this->priceCurrency->convert($base, $store);
        [$weee, $weeeTax] = $fixedProductTaxes ? $this->fixedProductTax->adjustments($fixedProductTaxes, $product, $store) : [0.0, 0.0];
        if ($this->taxHelper->priceIncludesTax($store)) {
            $value = $this->taxPrice($converted, true, $product, $store);

            return new Amount($value + $weee + $weeeTax, $value - $this->taxPrice($converted, false, $product, $store), $weee, $weeeTax);
        }
        if ($this->taxIncluded($store)) {
            $value = $this->taxPrice($converted, true, $product, $store);

            return new Amount($value + $weee + $weeeTax, $value - $converted, $weee, $weeeTax);
        }

        return new Amount($converted + $weee + $weeeTax, 0.0, $weee, $weeeTax);
    }

    public function regular(float $base, Product $product, StoreInterface $store, array $fixedProductTaxes = []): Amount
    {
        return $this->amount($base, false, $product, $store, 2, $fixedProductTaxes);
    }

    /**
     * A final price below the regular price is a discounted price, rounded to
     * the decimals of its source; one equal to it is the regular amount. An
     * aggregated final carries no source and takes four decimals, which
     * leaves a two-decimal special price as it is.
     */
    public function final(float $base, float $regularBase, Product $product, StoreInterface $store, int $precision = 4, array $fixedProductTaxes = []): Amount
    {
        return $base < $regularBase
            ? $this->amount($base, true, $product, $store, $precision, $fixedProductTaxes)
            : $this->amount($regularBase, false, $product, $store, 2, $fixedProductTaxes);
    }

    /**
     * A copy of the product that the tax service taxes with another class:
     * a composite's child, taxed as core taxes the child's own amounts.
     */
    public function forTaxClass(Product $product, ?int $taxClassId): Product
    {
        $copy = clone $product;
        $copy->setTaxClassId($taxClassId);

        return $copy;
    }

    public function taxIncluded(StoreInterface $store): bool
    {
        return $this->taxHelper->displayPriceIncludingTax() || $this->taxHelper->displayBothPrices($store);
    }

    private function taxPrice(float $amount, bool $includingTax, Product $product, StoreInterface $store): float
    {
        return (float)$this->catalogHelper->getTaxPrice($product, $amount, $includingTax, null, null, null, $store, null, false);
    }
}
