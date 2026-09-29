<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProduct\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontApi\Read\Amount;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Bundle price range as core's bundle amount calculator derives it, from the
 * bundle document and the documents of its selections.
 *
 * The minimum sums, over the required options, the selection with the lowest
 * value times quantity; a dynamic bundle without required options takes the
 * single lowest selection of all options. The maximum sums, over all options,
 * the highest selection, or every selection of a multi-select option. Regular
 * and final ranges are searched separately, each by its own value. A dynamic
 * selection's value is the child's regular or final price; a fixed bundle's
 * selection value is its fixed price or its percent of the bundle's regular
 * price, on top of the bundle's own base price. The final value pays the
 * bundle's special price percent or its qty-1 tier percent, whichever is
 * lower, as core's discount calculator does. Selections need an enabled child
 * document; when the bundle is salable, or out-of-stock products are hidden
 * for a fixed bundle, the child must be salable too, except for the maximum
 * of a multi-select option, which core sums without a stock filter.
 *
 * A dynamic bundle's selections are display amounts each taxed with the
 * child's own tax class, as core taxes each selection's amount; a fixed
 * bundle's total is taxed as a whole with the bundle's class.
 *
 * A fixed bundle with customizable options adds their price range in core
 * and is left to the core resolver.
 */
class BundlePriceRange implements PriceRangeInterface
{
    public function __construct(
        private readonly ProductPrice $productPrice,
        private readonly DisplayPrice $displayPrice,
        private readonly PriceCurrencyInterface $priceCurrency,
    ) {
    }

    public function range(Product $product, array $document, DocumentContext $context, bool $showOutOfStock): ?array
    {
        $productId = (int)$product->getId();
        $store = $context->store;
        $priceData = $context->priceData();
        $groupKey = $context->groupKey;
        // The bundle's option slice and its selection documents (by sku: stock, priceIndex) travel with the price data.
        $document += $priceData['bundleOptions'][$productId] ?? [];
        $selections = $priceData['bundle'][$productId] ?? [];
        $row = $this->productPrice->row((array)($document['prices'] ?? []), $groupKey);
        $options = array_values(array_filter(
            (array)($document['optionsV2'] ?? []),
            static fn($option) => ($option['type'] ?? null) === 'bundle'
        ));
        $fixed = ($row['type'] ?? null) === 'BUNDLE_FIXED';
        $customOptions = !empty($document['shopperInputOptions'])
            || count($options) !== count((array)($document['optionsV2'] ?? []));
        if ($row === null || !$options || ($fixed && $customOptions)) {
            return null;
        }

        $regular = (float)$row['regular'];
        $payPercent = $this->productPrice->bundlePayPercent($row);
        $discounted = static fn(float $value): float => $payPercent === null ? $value : round($value * $payPercent / 100, 2);
        $finalBase = 0.0;
        if ($fixed) {
            $finalBase = $discounted($regular);
            foreach ((array)($row['discounts'] ?? []) as $discount) {
                if (isset($discount['price'])) {
                    $finalBase = min($finalBase, (float)$discount['price']);
                }
            }
        }

        // Core prices a percent selection's regular amount on the base price and never converts it.
        $rate = $fixed ? (float)$this->priceCurrency->convert(1.0, $store) : 1.0;
        $bundleSalable = (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
        $stockFilter = $bundleSalable || ($fixed && !$showOutOfStock);
        $selectionValues = [];
        foreach ($options as $index => $option) {
            foreach ((array)($option['values'] ?? []) as $value) {
                $child = $selections[$value['sku'] ?? ''] ?? null;
                if ($child === null) {
                    continue;
                }
                $childPrice = $this->productPrice->indexEntry((array)($child['priceIndex'] ?? []), $groupKey);
                if ($fixed) {
                    $percent = ($value['priceType'] ?? null) === 'percent';
                    $unitRegular = $percent ? $regular * (float)$value['price'] / 100 : (float)$value['price'];
                    $unitFinal = $discounted($unitRegular);
                    if ($percent) {
                        $unitRegular /= $rate ?: 1.0;
                    }
                } elseif ($childPrice !== null) {
                    $unitRegular = (float)$childPrice['regular'];
                    $unitFinal = $discounted((float)$childPrice['final']);
                } else {
                    continue;
                }
                $qty = (float)($value['qty'] ?? 1);
                if ($fixed) {
                    $amounts = [
                        'regular' => new Amount(round($unitRegular, 4) * $qty),
                        'final' => new Amount(round($unitFinal, 4) * $qty),
                    ];
                } else {
                    $childProduct = $this->displayPrice->forTaxClass($product, isset($child['taxClassId']) ? (int)$child['taxClassId'] : null);
                    $childProduct->setTypeId('simple');
                    $taxes = (array)($child['fixedProductTaxes'] ?? []);
                    $unitRegular = round($unitRegular, 2);
                    $unitFinal = round($unitFinal, 2);
                    // Core rounds each selection's taxed amount to cents before it sums them, and the tax
                    // adjustment of a dynamic bundle is the taxed amount minus the selection's untaxed value.
                    $taxed = function (float $base, bool $discounted) use ($childProduct, $store, $qty, $taxes): Amount {
                        $amount = $this->displayPrice->amount($base, $discounted, $childProduct, $store, 2, $taxes);
                        $value = round($amount->value, 2);
                        $untaxed = round((float)$this->priceCurrency->convert($base, $store), 2);

                        return (new Amount($value, $value - $untaxed, round($amount->weee, 2), round($amount->weeeTax, 2)))->times($qty);
                    };
                    $amounts = [
                        'regular' => $taxed($unitRegular, false),
                        'final' => $taxed($unitFinal, $unitFinal < $unitRegular),
                    ];
                }
                $selectionValues[$index][] = $amounts + ['salable' => (bool)($child['stock']['isSalable'] ?? false)];
            }
        }

        $pick = static function (int $index, string $price, bool $lowest, bool $stockFilter) use ($selectionValues): ?Amount {
            $best = null;
            foreach ($selectionValues[$index] ?? [] as $selection) {
                if ($stockFilter && !$selection['salable']) {
                    continue;
                }
                $current = $selection[$price];
                if ($best === null || ($lowest ? $current->value < $best->value : $current->value > $best->value)) {
                    $best = $current;
                }
            }
            return $best;
        };
        $required = array_keys(array_filter($options, static fn($option) => !empty($option['required'])));

        $range = [];
        foreach (['regular', 'final'] as $price) {
            $base = new Amount($price === 'regular' ? ($fixed ? $regular : 0.0) : $finalBase);
            if (!$fixed && !empty($document['fixedProductTaxes'])) {
                $amount = $this->displayPrice->amount(0.0, false, $product, $store, 2, $document['fixedProductTaxes']);
                $base = new Amount(round($amount->value, 2), 0.0, round($amount->weee, 2), round($amount->weeeTax, 2));
            }
            $minimum = $base;
            if (!$fixed && !$required) {
                $lowest = null;
                foreach (array_keys($options) as $index) {
                    $candidate = $pick($index, $price, true, $stockFilter);
                    if ($candidate !== null && ($lowest === null || $candidate->value < $lowest->value)) {
                        $lowest = $candidate;
                    }
                }
                $minimum = Amount::sum($minimum, $lowest ?? new Amount(0.0));
            } else {
                foreach ($required as $index) {
                    $minimum = Amount::sum($minimum, $pick($index, $price, true, $stockFilter) ?? new Amount(0.0));
                }
            }
            $maximum = $base;
            foreach ($options as $index => $option) {
                if (in_array($option['renderType'] ?? '', ['checkbox', 'multi'], true)) {
                    foreach ($selectionValues[$index] ?? [] as $selection) {
                        $maximum = Amount::sum($maximum, $selection[$price]);
                    }
                } else {
                    $maximum = Amount::sum($maximum, $pick($index, $price, false, $stockFilter) ?? new Amount(0.0));
                }
            }
            $range[$price] = [$minimum, $maximum];
        }
        if ($fixed) {
            // The fixed total is one amount, taxed with the bundle's class; a final below the regular is discounted.
            $display = fn(Amount $total, Amount $regularTotal): Amount =>
                $this->displayPrice->amount($total->value, $total->value < $regularTotal->value, $product, $store, 2, (array)($document['fixedProductTaxes'] ?? []));

            return [
                $display($range['regular'][0], $range['regular'][0]),
                $display($range['final'][0], $range['regular'][0]),
                $display($range['regular'][1], $range['regular'][1]),
                $display($range['final'][1], $range['regular'][1]),
            ];
        }

        return [$range['regular'][0], $range['final'][0], $range['regular'][1], $range['final'][1]];
    }
}
