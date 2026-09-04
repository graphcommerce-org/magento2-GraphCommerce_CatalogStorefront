<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Read;

use Magento\Catalog\Model\Product;

/**
 * Fills, on the product value the executor hands to child fields, fields
 * whose core resolvers only derive from the model and the document, so the
 * executor returns them as plain values instead of running a resolver call
 * per product. The owning module lists its fields in di.xml under
 * `prefilledFields`, per type or interface name; a listed field resolves to
 * the filled value and to the core resolver when none is filled, which keeps
 * the core path for core-served products and for what a document cannot
 * answer. The hydration runs the prefillers in di.xml order, so a prefiller
 * may read what an earlier one filled from the model under KEY.
 */
interface PrefillerInterface
{
    public const KEY = '_gc_prefilled';

    /**
     * @param Product[] $models keyed by product id
     * @param array[] $documents keyed by product id
     * @return array<int, array<string, mixed>> the filled values per product id, for the fields the request selects
     */
    public function fill(array $models, array $documents, PrefillRequest $request): array;
}
