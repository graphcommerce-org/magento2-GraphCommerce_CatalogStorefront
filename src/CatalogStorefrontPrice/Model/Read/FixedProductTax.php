<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPrice\Model\Read;

use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Calculation;
use Magento\Weee\Helper\Data as WeeeHelper;
use Magento\Weee\Model\Tax as WeeeTax;

/**
 * The fixed product taxes of a product for the request, from the rows the
 * document carries: the rows of the request's destination country, its
 * region or none, the store's website or none, in core's row order, each
 * taxed the way core's weee model taxes it (the rate of the product's tax
 * class at the destination; with catalog prices including tax the amount
 * moves from the default rate to the destination's). Amounts are in the
 * base currency, unrounded, as core hands them to the adjustments.
 */
class FixedProductTax
{
    public function __construct(
        private readonly Calculation $calculation,
        private readonly WeeeHelper $weeeHelper,
        private readonly WeeeTax $weeeTax,
        private readonly TaxHelper $taxHelper,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Session $session,
    ) {
    }

    /**
     * Whether the store's fixed product taxes can move a price: enabled and
     * at least one attribute exists.
     */
    public function active(StoreInterface $store): bool
    {
        return $this->weeeHelper->isEnabled($store) && $this->weeeTax->getWeeeTaxAttributeCodes($store) !== [];
    }

    /**
     * The FPT display type of product lists, which GraphQL answers with: it
     * renders no product page.
     */
    public function listDisplayType(StoreInterface $store): int
    {
        return (int)$this->weeeHelper->getListPriceDisplayType($store);
    }

    /**
     * @param array[] $rows the document's fixedProductTaxes
     * @return array[] label, amount, amountExclTax, taxAmount per applying row
     */
    public function attributes(array $rows, Product $product, StoreInterface $store, bool $calculateTax): array
    {
        if (!$rows || !$this->weeeHelper->isEnabled($store)) {
            return [];
        }
        $request = $this->calculation->getRateRequest(null, null, null, $store, $this->session->getCustomerId() ?: null);
        $country = (string)$request->getCountryId();
        $region = (int)$request->getRegionId();
        $websiteId = (int)$store->getWebsiteId();
        $applying = array_filter(
            $rows,
            static fn(array $row) => (string)$row['country'] === $country
                && in_array((int)$row['region'], [$region, 0], true)
                && in_array((int)$row['website'], [$websiteId, 0], true)
                && (float)$row['value'] != 0.0
        );
        usort($applying, static fn(array $a, array $b) => [(int)$b['region'], (int)$b['website']] <=> [(int)$a['region'], (int)$a['website']]);
        $taxable = $calculateTax && $this->weeeHelper->isTaxable($store);
        $defaultPercent = $currentPercent = null;
        $result = [];
        foreach ($applying as $row) {
            $value = (float)$row['value'];
            $taxAmount = 0.0;
            $amountExclTax = $value;
            if ($taxable) {
                $defaultPercent ??= (float)$this->calculation->getRate(
                    $this->calculation->getDefaultRateRequest($store)->setProductClassId($product->getTaxClassId())
                );
                $currentPercent ??= (float)$this->calculation->getRate($request->setProductClassId($product->getTaxClassId()));
                if ($this->taxHelper->priceIncludesTax($store)) {
                    $amountInclTax = $value / (100 + $defaultPercent) * (100 + $currentPercent);
                    $taxAmount = $amountInclTax - $amountInclTax / (100 + $currentPercent) * 100;
                    $amountExclTax = $amountInclTax - $taxAmount;
                } else {
                    $appliedRates = $this->calculation->getAppliedRates($request);
                    if (is_array($appliedRates) && count($appliedRates) > 1) {
                        foreach ($appliedRates as $appliedRate) {
                            $taxAmount += $value * (float)$appliedRate['percent'] / 100;
                        }
                    } else {
                        $taxAmount = $value * $currentPercent / 100;
                    }
                }
            }
            $result[] = [
                'label' => (string)$row['label'],
                'amount' => $value,
                'amountExclTax' => $amountExclTax,
                'taxAmount' => $taxAmount,
            ];
        }

        return $result;
    }

    /**
     * The display currency parts a price carries as core's weee adjustments
     * add them: the amount when the list display includes FPT, its tax when
     * FPT is taxable and the price display is not excluding tax.
     *
     * @return array{0: float, 1: float} weee, weee tax
     */
    public function adjustments(array $rows, Product $product, StoreInterface $store): array
    {
        $attributes = $this->attributes($rows, $product, $store, true);
        if (!$attributes) {
            return [0.0, 0.0];
        }
        $type = $this->listDisplayType($store);
        $weee = $type !== WeeeTax::DISPLAY_EXCL
            ? (float)$this->priceCurrency->convert(array_sum(array_column($attributes, 'amountExclTax')), $store)
            : 0.0;
        $weeeTax = $type !== WeeeTax::DISPLAY_EXCL && $this->weeeHelper->isTaxable($store) && !$this->taxHelper->displayPriceExcludingTax()
            ? (float)$this->priceCurrency->convert(array_sum(array_column($attributes, 'taxAmount')), $store)
            : 0.0;

        return [$weee, $weeeTax];
    }
}
