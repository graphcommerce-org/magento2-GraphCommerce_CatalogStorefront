<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQlApi\Read;

use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use Magento\Store\Api\Data\StoreInterface;

/**
 * What one prefill run answers for: the document context plus the product
 * fields the query selects.
 */
final class PrefillRequest extends DocumentContext
{
    /**
     * @param string[] $requestedFields product fields the query selects; empty selects all
     * @param array|\Closure(): array $priceData the composite price data or its loader
     */
    public function __construct(
        StoreInterface $store,
        string $groupKey,
        private readonly array $requestedFields,
        array|\Closure $priceData,
    ) {
        parent::__construct($store, $groupKey, $priceData);
    }

    /**
     * Whether the query selects any of the given fields.
     */
    public function selects(string ...$fields): bool
    {
        return !$this->requestedFields || array_intersect($fields, $this->requestedFields) !== [];
    }
}
