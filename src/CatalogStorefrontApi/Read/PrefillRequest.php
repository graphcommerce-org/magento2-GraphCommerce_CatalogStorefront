<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Read;

use Magento\Store\Api\Data\StoreInterface;

/**
 * What one prefill run answers for: the store view, the customer group's
 * price key, the product fields the query selects, and the composite price
 * data, fetched on first use when the listing did not carry it.
 */
final class PrefillRequest
{
    /**
     * @param string[] $requestedFields product fields the query selects; empty selects all
     * @param array|\Closure(): array $priceData the composite price data by kind and parent id
     *   (`configurable` and `grouped` ranges, `bundle` selection documents, `bundleOptions` slices)
     */
    public function __construct(
        public readonly StoreInterface $store,
        public readonly string $groupKey,
        private readonly array $requestedFields,
        private array|\Closure $priceData,
    ) {
    }

    /**
     * Whether the query selects any of the given fields.
     */
    public function selects(string ...$fields): bool
    {
        return !$this->requestedFields || array_intersect($fields, $this->requestedFields) !== [];
    }

    public function priceData(): array
    {
        if ($this->priceData instanceof \Closure) {
            $this->priceData = ($this->priceData)();
        }

        return $this->priceData;
    }
}
