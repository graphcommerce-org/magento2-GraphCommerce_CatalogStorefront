<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Read;

use Magento\Store\Api\Data\StoreInterface;

/**
 * What a read of product documents answers for: the store view, the customer
 * group's price key, and the composite price data of the documents read,
 * fetched on first use when the read did not carry it.
 */
class DocumentContext
{
    /**
     * @param array|\Closure(): array $priceData the composite price data as
     *   ProductDocumentStorageInterface::priceData() shapes it, or its loader
     */
    public function __construct(
        public readonly StoreInterface $store,
        public readonly string $groupKey,
        private array|\Closure $priceData,
    ) {
    }

    public function priceData(): array
    {
        if ($this->priceData instanceof \Closure) {
            $this->priceData = ($this->priceData)();
        }

        return $this->priceData;
    }
}
