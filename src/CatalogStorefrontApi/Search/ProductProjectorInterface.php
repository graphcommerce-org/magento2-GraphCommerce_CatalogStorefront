<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Search;

/**
 * Builds the search-engine document for one assembled catalog product.
 *
 * The input is the product document after every feed slice has been applied,
 * so a projection consumes resolved prices and stock instead of implementing
 * those commerce rules a second time.
 */
interface ProductProjectorInterface
{
    /**
     * @param array $document assembled product document
     * @param array $attributes attribute metadata documents for the store view
     * @return array the source of one search document
     */
    public function project(array $document, array $attributes, ProjectionContext $context): array;
}
