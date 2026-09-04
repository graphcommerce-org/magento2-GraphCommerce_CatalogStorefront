<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Read;

use Magento\Catalog\Model\Product;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Product models built from documents, for any code that serves catalog
 * reads from the document store.
 */
interface ProductDocumentsInterface
{
    /** The model data key that holds the document a model was built from. */
    public const DOCUMENT_KEY = '_gc_document';

    /**
     * @param int[] $ids
     * @return array[] the documents that exist, keyed by product id
     */
    public function documents(string $storeViewCode, array $ids): array;

    /**
     * @param array[] $documents keyed by product id
     * @return Product[] keyed by product id; a document without the products feed slice yields none
     */
    public function build(StoreInterface $store, array $documents): array;

    /**
     * The composite price data of the composite products among the documents,
     * fetched when the closure runs, for a DocumentContext.
     *
     * @param array[] $documents keyed by product id
     * @return \Closure(): array
     */
    public function priceDataLoader(StoreInterface $store, string $groupKey, array $documents): \Closure;
}
