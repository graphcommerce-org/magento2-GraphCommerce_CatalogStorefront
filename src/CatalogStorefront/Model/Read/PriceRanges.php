<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;
use Magento\Catalog\Model\Product;

/**
 * The price range of a product, by the range of its type (di.xml `ranges`,
 * by product type id). A type without one is not answered from documents.
 */
class PriceRanges
{
    /**
     * @param PriceRangeInterface[] $ranges by product type id
     */
    public function __construct(
        private readonly array $ranges = [],
    ) {
    }

    /**
     * @return float[]|null [minimum regular, minimum final, maximum regular, maximum final]
     */
    public function range(Product $product, array $document, DocumentContext $context, bool $showOutOfStock): ?array
    {
        return ($this->ranges[$product->getTypeId()] ?? null)
            ?->range((int)$product->getId(), $document, $context, $showOutOfStock);
    }
}
