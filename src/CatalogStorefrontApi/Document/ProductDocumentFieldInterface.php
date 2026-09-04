<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Document;

/**
 * A product document field computed at index time from the products feed
 * row, for what the read side needs and the feed lacks. The products writer
 * runs the fields (di.xml `fields`) over every batch before it is stored.
 */
interface ProductDocumentFieldInterface
{
    /**
     * @param array[] $documents products feed rows of one store view, keyed by product id
     * @return array[] the documents with the field added, keyed by product id
     */
    public function add(string $storeViewCode, array $documents): array;
}
