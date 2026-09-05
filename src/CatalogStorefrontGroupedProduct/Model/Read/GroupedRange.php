<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGroupedProduct\Model\Read;

use GraphCommerce\CatalogStorefrontPrice\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;
use Magento\Catalog\Model\Product;

/**
 * Core takes, over the associated products, the lowest regular and the
 * lowest final price each on its own, and the maximum equals the minimum;
 * out-of-stock children count only when out-of-stock products are shown.
 * The range is taxed with the grouped product's own tax class.
 */
class GroupedRange implements PriceRangeInterface
{
    public function __construct(
        private readonly DisplayPrice $displayPrice,
    ) {
    }

    public function range(Product $product, array $document, DocumentContext $context, bool $showOutOfStock): ?array
    {
        $ranges = $context->priceData()['grouped'][(int)$product->getId()] ?? null;
        $range = $ranges === null ? null : ($showOutOfStock ? $ranges['all'] : $ranges['salable']);
        if ($range === null) {
            return null;
        }
        $regular = $this->displayPrice->regular($range[0], $product, $context->store);
        $final = $this->displayPrice->final($range[1], $range[0], $product, $context->store);

        return [$regular, $final, $regular, $final];
    }
}
