<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGroupedProduct\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;

/**
 * Core takes, over the associated products, the lowest regular and the
 * lowest final price each on its own, and the maximum equals the minimum;
 * out-of-stock children count only when out-of-stock products are shown.
 */
class GroupedRange implements PriceRangeInterface
{
    public function range(int $productId, array $document, DocumentContext $context, bool $showOutOfStock): ?array
    {
        $ranges = $context->priceData()['grouped'][$productId] ?? null;
        $range = $ranges === null ? null : ($showOutOfStock ? $ranges['all'] : $ranges['salable']);

        return $range === null ? null : [$range[0], $range[1], $range[0], $range[1]];
    }
}
