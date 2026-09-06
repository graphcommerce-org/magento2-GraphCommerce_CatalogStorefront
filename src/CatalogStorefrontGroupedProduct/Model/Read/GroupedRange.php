<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGroupedProduct\Model\Read;

use GraphCommerce\CatalogStorefrontPrice\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontApi\Read\Amount;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;
use Magento\Catalog\Model\Product;

/**
 * Core takes, over the associated products, the lowest regular and the
 * lowest final base price each on its own, taxes each with the tax class of
 * the child that carries it, and the maximum equals the minimum;
 * out-of-stock children count only when out-of-stock products are shown.
 * When children of different tax classes share the lowest base price, the
 * lowest taxed amount is taken; core takes the last child in position order.
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
        $byTaxClass = $range[4] ?? [];
        if (!$byTaxClass) {
            $byTaxClass = [(int)$product->getTaxClassId() => $range];
        }
        $regular = null;
        $final = null;
        foreach ($byTaxClass as $taxClassId => [$minRegular, $minFinal]) {
            $child = $this->displayPrice->forTaxClass($product, $taxClassId ?: null);
            if ($minRegular <= $range[0]) {
                $amount = $this->displayPrice->regular($minRegular, $child, $context->store);
                $regular = $regular === null || $amount->value < $regular->value ? $amount : $regular;
            }
            if ($minFinal <= $range[1]) {
                $amount = $this->displayPrice->final($minFinal, $minRegular, $child, $context->store);
                $final = $final === null || $amount->value < $final->value ? $amount : $final;
            }
        }

        return [$regular, $final, $regular, $final];
    }
}
