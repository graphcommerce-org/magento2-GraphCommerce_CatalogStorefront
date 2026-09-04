<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProduct\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;

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
 * A fixed bundle with customizable options adds their price range in core
 * and is left to the core resolver.
 */
class BundlePriceRange implements PriceRangeInterface
{
    public function __construct(
        private readonly ProductPrice $productPrice,
    ) {
    }

    public function range(int $productId, array $document, DocumentContext $context, bool $showOutOfStock): ?array
    {
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

        $bundleSalable = (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
        $stockFilter = $bundleSalable || ($fixed && !$showOutOfStock);
        $selectionValues = [];
        foreach ($options as $index => $option) {
            foreach ((array)($option['values'] ?? []) as $value) {
                $child = $selections[$value['sku'] ?? ''] ?? null;
                if ($child === null) {
                    continue;
                }
                $childPrice = $child['priceIndex'][$groupKey] ?? null;
                if ($fixed) {
                    $unitRegular = ($value['priceType'] ?? null) === 'percent'
                        ? $regular * (float)$value['price'] / 100
                        : (float)$value['price'];
                    $unitFinal = $discounted($unitRegular);
                } elseif ($childPrice !== null) {
                    $unitRegular = (float)$childPrice['regular'];
                    $unitFinal = $discounted((float)$childPrice['final']);
                } else {
                    continue;
                }
                $selectionValues[$index][] = [
                    'regular' => round($unitRegular, 4),
                    'final' => round($unitFinal, 4),
                    'qty' => (float)($value['qty'] ?? 1),
                    'salable' => (bool)($child['stock']['isSalable'] ?? false),
                ];
            }
        }

        $amount = static fn(array $selection, string $price): float =>
            ($fixed ? $selection[$price] : round($selection[$price], 2)) * $selection['qty'];
        $pick = static function (int $index, string $price, bool $lowest, bool $stockFilter) use ($selectionValues, $amount): ?float {
            $best = null;
            foreach ($selectionValues[$index] ?? [] as $selection) {
                if ($stockFilter && !$selection['salable']) {
                    continue;
                }
                $current = $amount($selection, $price);
                if ($best === null || ($lowest ? $current < $best : $current > $best)) {
                    $best = $current;
                }
            }
            return $best;
        };
        $required = array_keys(array_filter($options, static fn($option) => !empty($option['required'])));

        $range = [];
        foreach (['regular', 'final'] as $price) {
            $base = $price === 'regular' ? ($fixed ? $regular : 0.0) : $finalBase;
            $minimum = $base;
            if (!$fixed && !$required) {
                $lowest = null;
                foreach (array_keys($options) as $index) {
                    $candidate = $pick($index, $price, true, $stockFilter);
                    if ($candidate !== null && ($lowest === null || $candidate < $lowest)) {
                        $lowest = $candidate;
                    }
                }
                $minimum += $lowest ?? 0.0;
            } else {
                foreach ($required as $index) {
                    $minimum += $pick($index, $price, true, $stockFilter) ?? 0.0;
                }
            }
            $maximum = $base;
            foreach ($options as $index => $option) {
                if (in_array($option['renderType'] ?? '', ['checkbox', 'multi'], true)) {
                    foreach ($selectionValues[$index] ?? [] as $selection) {
                        $maximum += $amount($selection, $price);
                    }
                } else {
                    $maximum += $pick($index, $price, false, $stockFilter) ?? 0.0;
                }
            }
            $range[$price] = [$minimum, $maximum];
        }

        return [$range['regular'][0], $range['final'][0], $range['regular'][1], $range['final'][1]];
    }
}
