<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

/**
 * Price semantics shared by the feed writer and the read plugins.
 *
 * A product's price row is the customer group's own feed row when the feed
 * exported one (group-specific catalog rule, group or tier prices), else the
 * fallback row every product carries under group code "0". The final price is
 * the regular price lowered by the best discount or single-quantity tier
 * price, which is the minimum the core BasePrice takes over its providers.
 */
class ProductPrice
{
    public const FALLBACK_GROUP_KEY = 'g0';

    public function groupKey(int $customerGroupId): string
    {
        return 'g' . sha1((string)$customerGroupId);
    }

    /**
     * @param array<string, array> $prices the document's price rows by group key
     */
    public function row(array $prices, string $groupKey): ?array
    {
        $row = $prices[$groupKey] ?? $prices[self::FALLBACK_GROUP_KEY] ?? null;

        return isset($row['regular']) ? $row : null;
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
