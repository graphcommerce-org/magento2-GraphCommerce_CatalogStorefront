<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontApi\Read\Amount;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;
use Magento\Catalog\Model\Product;

/**
 * A configurable's range is the aggregation over its variants' price index:
 * the salable variants, or all enabled variants when out-of-stock products
 * are shown and the parent itself is not salable. Without a variant that
 * prices it, core answers zero. The range is taxed with the configurable's
 * own tax class.
 */
class ConfigurableRange implements PriceRangeInterface
{
    public function __construct(
        private readonly DisplayPrice $displayPrice,
    ) {
    }

    public function range(Product $product, array $document, DocumentContext $context, bool $showOutOfStock): ?array
    {
        $ranges = $context->priceData()['configurable'][(int)$product->getId()] ?? null;
        if ($ranges === null) {
            return null;
        }
        $parentSalable = (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
        $range = $showOutOfStock
            ? ($parentSalable ? ($ranges['salable'] ?? $ranges['all']) : $ranges['all'])
            : $ranges['salable'];
        if ($range === null) {
            return [new Amount(0.0), new Amount(0.0), new Amount(0.0), new Amount(0.0)];
        }
        [$minRegular, $minFinal, $maxRegular, $maxFinal] = $range;
        $store = $context->store;

        return [
            $this->displayPrice->regular($minRegular, $product, $store),
            $this->displayPrice->final($minFinal, $minRegular, $product, $store),
            $this->displayPrice->regular($maxRegular, $product, $store),
            $this->displayPrice->final($maxFinal, $maxRegular, $product, $store),
        ];
    }
}
