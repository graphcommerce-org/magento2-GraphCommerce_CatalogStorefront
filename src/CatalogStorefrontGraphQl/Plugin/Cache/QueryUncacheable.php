<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Cache;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use Magento\GraphQlCache\Model\CacheableQuery;

/** Disables HTTP response caching for document-mode and keyed requests. */
class QueryUncacheable
{
    public function __construct(
        private readonly StorefrontKey $key,
        private readonly Mode $mode,
    ) {
    }

    public function afterIsCacheable(CacheableQuery $subject, bool $result): bool
    {
        return $result && !$this->mode->documents() && !$this->key->granted();
    }
}
