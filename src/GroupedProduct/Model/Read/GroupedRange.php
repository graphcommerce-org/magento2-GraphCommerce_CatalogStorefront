<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGroupedProduct\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\PrefillRequest;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;

/**
 * Core takes, over the associated products, the lowest regular and the
 * lowest final price each on its own, and the maximum equals the minimum;
 * out-of-stock children count only when out-of-stock products are shown.
 */
class GroupedRange implements PriceRangeInterface
{
    public function range(int $productId, array $document, PrefillRequest $request, bool $showOutOfStock): ?array
    {
        $ranges = $request->priceData()['grouped'][$productId] ?? null;
        $range = $ranges === null ? null : ($showOutOfStock ? $ranges['all'] : $ranges['salable']);

        return $range === null ? null : [$range[0], $range[1], $range[0], $range[1]];
    }
}
