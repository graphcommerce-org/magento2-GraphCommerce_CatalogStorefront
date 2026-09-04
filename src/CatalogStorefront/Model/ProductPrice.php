<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

/**
 * Price semantics shared by the feed writer and the read plugins.
 *
 * A product's price rows are the feed rows by customer group id: the group's
 * own row when the feed exported one (group-specific catalog rule, group or
 * tier prices), else the fallback row every product carries under group 0.
 * The final price is the regular price lowered by the best discount or
 * single-quantity tier price, which is the minimum the core BasePrice takes
 * over its providers. The price index holds, per customer group, the regular
 * and final price with the fallback resolved, as base currency floats before
 * tax; the composite price aggregations run over it.
 */
class ProductPrice
{
    public const FALLBACK_GROUP = '0';

    public function groupKey(int $customerGroupId): string
    {
        return (string)$customerGroupId;
    }

    /**
     * @param array[] $prices the document's price rows, each with its `group`
     */
    public function row(array $prices, string $groupKey): ?array
    {
        $fallback = null;
        foreach ($prices as $row) {
            $group = (string)($row['group'] ?? '');
            if ($group === $groupKey && isset($row['regular'])) {
                return $row;
            }
            if ($group === self::FALLBACK_GROUP) {
                $fallback = $row;
            }
        }

        return isset($fallback['regular']) ? $fallback : null;
    }

    /**
     * @param array[] $index the document's price index entries
     * @return array{regular: float, final: float}|null
     */
    public function indexEntry(array $index, string $groupKey): ?array
    {
        foreach ($index as $entry) {
            if ((string)($entry['group'] ?? '') === $groupKey && isset($entry['regular'], $entry['final'])) {
                return ['regular' => (float)$entry['regular'], 'final' => (float)$entry['final']];
            }
        }

        return null;
    }

    /**
     * The percent of its regular price a bundle is sold for: a bundle special
     * price is a percent to pay, a tier price for one piece a percent off;
     * null without either.
     */
    public function bundlePayPercent(array $row): ?float
    {
        $payPercent = null;
        foreach ((array)($row['discounts'] ?? []) as $discount) {
            if (($discount['code'] ?? null) === 'special_price' && isset($discount['percentage'])) {
                $payPercent = min($payPercent ?? 100.0, (float)$discount['percentage']);
            }
        }
        foreach ((array)($row['tierPrices'] ?? []) as $tier) {
            if ((float)($tier['qty'] ?? 1) <= 1 && isset($tier['percentage'])) {
                $payPercent = min($payPercent ?? 100.0, max(0.0, min(100.0, 100.0 - (float)$tier['percentage'])));
            }
        }

        return $payPercent;
    }

    public function finalPrice(array $row): float
    {
        $regular = (float)$row['regular'];
        $final = $regular;
        foreach ((array)($row['discounts'] ?? []) as $discount) {
            $final = min($final, $this->discountedPrice($regular, $discount));
        }
        foreach ((array)($row['tierPrices'] ?? []) as $tier) {
            if ((float)($tier['qty'] ?? 1) <= 1) {
                $final = min($final, $this->discountedPrice($regular, $tier));
            }
        }

        return max(0.0, $final);
    }

    /**
     * @param array{price?: float|null, percentage?: float|null} $discount
     */
    private function discountedPrice(float $regular, array $discount): float
    {
        if (isset($discount['price'])) {
            return (float)$discount['price'];
        }

        return $regular * (1 - (float)($discount['percentage'] ?? 0) / 100);
    }
}
