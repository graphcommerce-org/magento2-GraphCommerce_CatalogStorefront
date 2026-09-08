<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPrice\Model\Read\Price;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;
use Magento\Catalog\Model\Product;

/**
 * The range of a product priced on its own: its regular and final price are
 * both ends.
 */
class SingleRange implements PriceRangeInterface
{
    public function __construct(
        private readonly ProductPrice $productPrice,
        private readonly DisplayPrice $displayPrice,
    ) {
    }

    public function range(Product $product, array $document, DocumentContext $context, bool $showOutOfStock): ?array
    {
        $row = $this->productPrice->row((array)($document['prices'] ?? []), $context->groupKey);
        if ($row === null) {
            return null;
        }
        $regularBase = (float)$row['regular'];
        $fixedProductTaxes = (array)($document['fixedProductTaxes'] ?? []);
        $regular = $this->displayPrice->regular($regularBase, $product, $context->store, $fixedProductTaxes);
        $final = $this->displayPrice->final(
            $this->productPrice->finalPrice($row),
            $regularBase,
            $product,
            $context->store,
            $this->productPrice->finalPrecision($row),
            $fixedProductTaxes
        );

        return [$regular, $final, $regular, $final];
    }
}
