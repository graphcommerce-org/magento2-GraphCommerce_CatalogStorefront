<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;

/**
 * A configurable's range is the aggregation over its variants' price index:
 * the salable variants, or all enabled variants when out-of-stock products
 * are shown and the parent itself is not salable. Without a variant that
 * prices it, core answers zero.
 */
class ConfigurableRange implements PriceRangeInterface
{
    public function range(int $productId, array $document, DocumentContext $context, bool $showOutOfStock): ?array
    {
        $ranges = $context->priceData()['configurable'][$productId] ?? null;
        if ($ranges === null) {
            return null;
        }
        $parentSalable = (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
        $range = $showOutOfStock
            ? ($parentSalable ? ($ranges['salable'] ?? $ranges['all']) : $ranges['all'])
            : $ranges['salable'];

        return $range ?? [0.0, 0.0, 0.0, 0.0];
    }
}
