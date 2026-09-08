<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPriceGraphQl\Model\Prefill;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\FixedProductTax;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\PriceRanges;
use GraphCommerce\CatalogStorefrontApi\Read\Amount;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;
use Magento\Catalog\Model\Product;
use Magento\CatalogGraphQl\Model\Resolver\Product\Price\Discount;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Weee\Model\Tax as WeeeTax;

/**
 * The price range, the deprecated price and the tier prices, from the price
 * rows and the range of the product's type, in the request's display
 * currency and tax setup. With fixed product taxes active a composite
 * carries the taxes of its children, which the aggregated ranges do not
 * see, so composites go to core then.
 */
class Prices implements PrefillerInterface
{
    private const OWN_PRICE_TYPES = ['simple', 'virtual', 'downloadable'];

    public function __construct(
        private readonly DisplayPrice $displayPrice,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Discount $discount,
        private readonly ProductPrice $productPrice,
        private readonly PriceRanges $priceRanges,
        private readonly FixedProductTax $fixedProductTax,
        private readonly TaxHelper $taxHelper,
        private readonly Strict $strict,
    ) {
    }

    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('price_range', 'price', 'price_tiers', 'tier_prices')) {
            return [];
        }
        $store = $request->store;
        $currency = $store->getCurrentCurrencyCode();
        $showOutOfStock = $this->displayPrice->showOutOfStock($store);
        $fixedProductTaxes = $this->fixedProductTax->active($store);
        $output = [];
        foreach ($models as $id => $product) {
            if ($fixedProductTaxes && !in_array($product->getTypeId(), self::OWN_PRICE_TYPES, true)) {
                $this->strict->fallback(self::class, 'fixed product taxes on a ' . $product->getTypeId());
                continue;
            }
            $document = $documents[$id] ?? [];
            $range = $this->priceRanges->range($product, $document, $request, $showOutOfStock);
            if ($range === null) {
                continue;
            }
            [$minRegular, $minFinal, $maxRegular, $maxFinal] = $range;
            $taxes = $fixedProductTaxes ? $this->fixedProductTaxes((array)($document['fixedProductTaxes'] ?? []), $product, $store) : [];
            $priceRange = [
                'minimum_price' => $this->format($minRegular->value, $minFinal->value, $product, $currency) + $taxes,
                'maximum_price' => $this->format($maxRegular->value, $maxFinal->value, $product, $currency) + $taxes,
            ];
            // Core adds the separately purchased link prices to the rounded maximum after the discount is computed.
            if (!empty($document['linksPurchasedSeparately'])) {
                $linkPrice = 0.0;
                foreach ((array)($document['optionsV2'] ?? []) as $option) {
                    if (($option['type'] ?? null) === 'downloadable') {
                        $linkPrice += array_sum(array_column((array)($option['values'] ?? []), 'price'));
                    }
                }
                if ($linkPrice > 0) {
                    $priceRange['maximum_price']['regular_price']['value'] += $linkPrice;
                    $priceRange['maximum_price']['final_price']['value'] += $linkPrice;
                }
            }
            $filled = [
                'price_range' => $priceRange,
                'price' => [
                    'minimalPrice' => $this->amount($minFinal, $store),
                    'regularPrice' => $this->amount($minRegular, $store),
                    'maximalPrice' => $this->amount($maxFinal, $store),
                ],
            ];
            if ($request->selects('price_tiers', 'tier_prices')) {
                // Core's tier collection loads no tax class, so its discount base is the regular price before tax.
                $filled += $this->tiers($document, $request->groupKey, $minRegular->value - $minRegular->tax, $store);
            }
            $output[$id] = $filled;
        }

        return $output;
    }

    private function format(float $regular, float $final, Product $product, string $currency): array
    {
        return [
            'regular_price' => ['value' => $this->priceCurrency->roundPrice($regular), 'currency' => $currency],
            'final_price' => ['value' => $this->priceCurrency->roundPrice($final), 'currency' => $currency],
            'discount' => $this->discount->getDiscountByDifference($regular, $final),
            'model' => $product,
        ];
    }

    /**
     * The fixed_product_taxes of a price, as core's resolver lists them: the
     * amount by the tax display type, in the base currency under the display
     * currency's code, as core answers it.
     *
     * @return array{fixed_product_taxes?: array[]}
     */
    private function fixedProductTaxes(array $rows, Product $product, StoreInterface $store): array
    {
        $listType = $this->fixedProductTax->listDisplayType($store);
        $displayType = (int)$this->taxHelper->getPriceDisplayType($store);
        $taxes = [];
        foreach ($this->fixedProductTax->attributes($rows, $product, $store, in_array($listType, [WeeeTax::DISPLAY_INCL_DESCR, WeeeTax::DISPLAY_EXCL_DESCR_INCL], true)) as $attribute) {
            $taxes[] = [
                'amount' => [
                    'value' => match ($displayType) {
                        TaxConfig::DISPLAY_TYPE_EXCLUDING_TAX => $attribute['amountExclTax'],
                        TaxConfig::DISPLAY_TYPE_INCLUDING_TAX => $attribute['amountExclTax'] + $attribute['taxAmount'],
                        default => $attribute['amount'],
                    },
                    'currency' => $store->getCurrentCurrencyCode(),
                ],
                'label' => $attribute['label'],
            ];
        }

        return ['fixed_product_taxes' => $taxes];
    }

    /**
     * The deprecated price shape: the unrounded amount and the adjustments
     * it carries, in core's adjustment order.
     */
    public function amount(Amount $amount, StoreInterface $store): array
    {
        $currency = $store->getCurrentCurrencyCode();
        $adjustments = [];
        if ($amount->tax) {
            $adjustments[] = [
                'code' => 'TAX',
                'amount' => ['value' => $amount->tax, 'currency' => $currency],
                'description' => $this->displayPrice->taxIncluded($store) ? 'INCLUDED' : 'EXCLUDED',
            ];
        }
        foreach (['WEEE' => $amount->weee, 'WEEE_TAX' => $amount->weeeTax] as $code => $value) {
            if ($value) {
                $adjustments[] = ['code' => $code, 'amount' => ['value' => $value, 'currency' => $currency], 'description' => 'INCLUDED'];
            }
        }

        return ['amount' => ['value' => $amount->value, 'currency' => $currency], 'adjustments' => $adjustments];
    }

    /**
     * The tier prices of the customer group's price row, as core lists them:
     * a percent tier's price is the percent off the product price, a fixed
     * tier's its value, converted and rounded without tax; the discount is
     * against the display regular price. Of two tiers for one quantity the
     * lower price stays. The deprecated shape carries the group only as the
     * feed has it: every tier reads as for all groups.
     *
     * @return array{price_tiers: array[], tier_prices: array[]}
     */
    private function tiers(array $document, string $groupKey, float $regularPrice, StoreInterface $store): array
    {
        $currency = $store->getCurrentCurrencyCode();
        $row = $this->productPrice->row((array)($document['prices'] ?? []), $groupKey);
        $byQty = [];
        foreach ((array)($row['tierPrices'] ?? []) as $tier) {
            $qty = (float)($tier['qty'] ?? 0);
            $percentage = isset($tier['percentage']) ? (float)$tier['percentage'] : null;
            $value = $this->priceCurrency->convertAndRound(
                $percentage !== null
                    ? (float)$row['regular'] * (1 - $percentage / 100)
                    : (float)($tier['price'] ?? 0),
                $store
            );
            if (isset($byQty[$qty]) && $byQty[$qty]['value'] <= $value) {
                continue;
            }
            $byQty[$qty] = ['qty' => $qty, 'value' => $value, 'percentage' => $percentage];
        }
        $priceTiers = [];
        $tierPrices = [];
        foreach ($byQty as $tier) {
            $priceTiers[] = [
                'discount' => $tier['percentage'] !== null
                    ? $this->discount->getDiscountByPercent($regularPrice, $tier['percentage'])
                    : $this->discount->getDiscountByDifference($regularPrice, $tier['value']),
                'quantity' => $tier['qty'],
                'final_price' => ['value' => $tier['value'], 'currency' => $currency],
            ];
            $tierPrices[] = [
                'customer_group_id' => '32000',
                'qty' => $tier['qty'],
                'value' => $tier['value'],
                'percentage_value' => null,
                'website_id' => null,
            ];
        }

        return ['price_tiers' => $priceTiers, 'tier_prices' => $tierPrices];
    }
}
