<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Read;

/**
 * The price range of one product type from its document and the composite
 * price data. The price prefiller holds one per product type id (di.xml
 * `ranges`), so a product type module brings its own.
 */
interface PriceRangeInterface
{
    /**
     * @return float[]|null [minimum regular, minimum final, maximum regular, maximum final];
     *   null when the document cannot answer, which leaves the field to the core resolver
     */
    public function range(int $productId, array $document, PrefillRequest $request, bool $showOutOfStock): ?array;
}
