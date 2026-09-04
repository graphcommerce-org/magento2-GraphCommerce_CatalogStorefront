<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Read;

use Magento\Catalog\Model\Product;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Product models built from documents, for a resolver plugin that serves a
 * field from the documents of other products (variants, bundle selections,
 * grouped children, linked products).
 */
interface HydrationInterface
{
    /** The model data key that holds the document a model was built from. */
    public const DOCUMENT_KEY = '_gc_document';

    /**
     * Whether the read path serves from documents at all.
     */
    public function enabled(): bool;

    public function groupKey(?ContextInterface $context): string;

    /**
     * @param int[] $ids
     * @return array[] the documents that exist, keyed by product id
     */
    public function documents(string $storeViewCode, array $ids): array;

    /**
     * Models of the documents, prefilled for the requested fields.
     *
     * @param array[] $documents keyed by product id
     * @param string[] $requestedFields product fields the query selects; empty selects all
     * @return Product[] keyed by product id
     */
    public function models(StoreInterface $store, ?ContextInterface $context, array $documents, array $requestedFields): array;

    /**
     * Runs the prefillers over models already built.
     *
     * @param Product[] $models keyed by product id
     * @param array[] $documents keyed by product id
     * @param string[] $requestedFields product fields to fill; empty fills all
     */
    public function prefill(StoreInterface $store, ?ContextInterface $context, array $models, array $documents, array $requestedFields): void;
}
