<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQlApi\Read;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Model\Product;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Product models built from documents and prefilled for a GraphQL query, for
 * a resolver plugin that serves a field from the documents of other products
 * (variants, bundle selections, grouped children, linked products).
 */
interface HydrationInterface extends ProductDocumentsInterface
{
    /**
     * Whether the read path serves from documents at all.
     */
    public function enabled(): bool;

    public function groupKey(?ContextInterface $context): string;

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
