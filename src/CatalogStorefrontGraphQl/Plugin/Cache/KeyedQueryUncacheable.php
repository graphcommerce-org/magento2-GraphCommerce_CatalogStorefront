<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Cache;

use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use Magento\GraphQlCache\Model\CacheableQuery;

/**
 * A request under the storefront key is never cacheable: its answer is the path the header
 * picked, and a cache that hashes on the URL alone would hand it to every visitor.
 */
class KeyedQueryUncacheable
{
    public function __construct(
        private readonly StorefrontKey $key,
    ) {
    }

    public function afterIsCacheable(CacheableQuery $subject, bool $result): bool
    {
        return $result && !$this->key->granted();
    }
}
