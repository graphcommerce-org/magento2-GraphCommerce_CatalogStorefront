<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Search;

/**
 * Magento scope needed to name and populate one store-view search projection.
 */
final class ProjectionContext
{
    public function __construct(
        public readonly int $storeId,
        public readonly string $storeViewCode,
        public readonly int $websiteId,
    ) {
        if ($this->storeId < 0 || $this->websiteId < 0 || trim($this->storeViewCode) === '') {
            throw new \InvalidArgumentException('A search projection needs a non-negative store and website id and a store-view code.');
        }
    }
}
