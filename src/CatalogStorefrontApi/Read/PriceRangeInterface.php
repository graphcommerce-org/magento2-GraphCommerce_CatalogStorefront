<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Read;

use Magento\Catalog\Model\Product;

/**
 * The price range of one product type from its document and the composite
 * price data, as display amounts. `PriceRanges` holds one per product type
 * id (di.xml `ranges`), so a product type module brings its own.
 */
interface PriceRangeInterface
{
    /**
     * @return Amount[]|null [minimum regular, minimum final, maximum regular, maximum final];
     *   null when the document cannot answer
     */
    public function range(Product $product, array $document, DocumentContext $context, bool $showOutOfStock): ?array;
}
