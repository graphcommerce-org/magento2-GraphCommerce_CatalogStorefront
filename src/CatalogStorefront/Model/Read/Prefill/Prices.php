<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read\Prefill;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Read\PriceDisplay;
use GraphCommerce\CatalogStorefrontApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontApi\Read\PrefillRequest;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogGraphQl\Model\Resolver\Product\Price\Discount;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * The price range, the deprecated price and the tier prices, from the price
 * rows and the range of the product's type (di.xml `ranges`, by type id).
 * Only a servable price display setup is answered.
 */
class Prices implements PrefillerInterface
{
    /**
     * @param PriceRangeInterface[] $ranges by product type id
     */
    public function __construct(
        private readonly PriceDisplay $priceDisplay,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Discount $discount,
        private readonly ProductPrice $productPrice,
        private readonly array $ranges = [],
    ) {
    }

    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('price_range', 'price', 'price_tiers', 'tier_prices')
            || !$this->priceDisplay->servable($request->store)
        ) {
            return [];
        }
        $currency = $request->store->getCurrentCurrencyCode();
        $showOutOfStock = $this->priceDisplay->showOutOfStock($request->store);
        $output = [];
        foreach ($models as $id => $product) {
            $document = $documents[$id] ?? [];
            $range = ($this->ranges[$product->getTypeId()] ?? null)?->range($id, $document, $request, $showOutOfStock);
            if ($range === null) {
                continue;
            }
            $priceRange = $this->priceRange($product, $document, $range, $currency);
            $amount = static fn(float $value): array => ['amount' => ['value' => $value, 'currency' => $currency], 'adjustments' => []];
            $filled = [
                'price_range' => $priceRange,
                'price' => [
                    'minimalPrice' => $amount($priceRange['minimum_price']['final_price']['value']),
                    'regularPrice' => $amount($priceRange['minimum_price']['regular_price']['value']),
                    'maximalPrice' => $amount($priceRange['maximum_price']['final_price']['value']),
                ],
            ];
            if ($request->selects('price_tiers', 'tier_prices')) {
                $filled += $this->tiers($document, $request->groupKey, $priceRange['minimum_price']['regular_price']['value'], $currency);
            }
            $output[$id] = $filled;
        }

        return $output;
    }

    /**
     * The tier prices of the customer group's price row, as core lists them:
     * a percent tier's price is the percent off the product price, a fixed
     * tier's its value; of two tiers for one quantity the lower price stays.
     * The deprecated shape carries the group only as the feed has it: every
     * tier reads as for all groups.
     *
     * @return array{price_tiers: array[], tier_prices: array[]}
     */
    private function tiers(array $document, string $groupKey, float $regularPrice, string $currency): array
    {
        $row = $this->productPrice->row((array)($document['prices'] ?? []), $groupKey);
        $byQty = [];
        foreach ((array)($row['tierPrices'] ?? []) as $tier) {
            $qty = (float)($tier['qty'] ?? 0);
            $percentage = isset($tier['percentage']) ? (float)$tier['percentage'] : null;
            $value = $this->priceCurrency->convertAndRound(
                $percentage !== null
                    ? (float)$row['regular'] * (1 - $percentage / 100)
                    : (float)($tier['price'] ?? 0)
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

    private function priceRange(Product $product, array $document, array $range, string $currency): array
    {
        [$minRegular, $minFinal, $maxRegular, $maxFinal] = $range;
        $format = fn(float $regular, float $final): array => [
            'regular_price' => ['value' => $this->priceCurrency->roundPrice($regular), 'currency' => $currency],
            'final_price' => ['value' => $this->priceCurrency->roundPrice($final), 'currency' => $currency],
            'discount' => $this->discount->getDiscountByDifference($regular, $final),
            'model' => $product,
        ];
        $result = [
            'minimum_price' => $format($minRegular, $minFinal),
            'maximum_price' => $format($maxRegular, $maxFinal),
        ];

        // Core adds the separately purchased link prices to the rounded maximum
        // after the discount is computed.
        if (!empty($document['linksPurchasedSeparately'])) {
            $linkPrice = 0.0;
            foreach ((array)($document['optionsV2'] ?? []) as $option) {
                if (($option['type'] ?? null) === 'downloadable') {
                    $linkPrice += array_sum(array_column((array)($option['values'] ?? []), 'price'));
                }
            }
            if ($linkPrice > 0) {
                $result['maximum_price']['regular_price']['value'] += $linkPrice;
                $result['maximum_price']['final_price']['value'] += $linkPrice;
            }
        }

        return $result;
    }
}
