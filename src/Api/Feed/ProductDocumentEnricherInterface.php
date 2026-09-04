<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Feed;

/**
 * Adds to the product documents of one store view, at index time, what the
 * read side needs and the products feed lacks. The products applier runs the
 * enrichers (di.xml `enrichers`) over every batch before it is stored.
 */
interface ProductDocumentEnricherInterface
{
    /**
     * @param array[] $documents products feed rows of one store view, keyed by product id
     * @return array[] the documents, keyed by product id
     */
    public function enrich(string $storeViewCode, array $documents): array;
}
