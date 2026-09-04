<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Cache;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Mode;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\GraphQlCache\Model\CacheId\CacheIdFactorProviderInterface;
use Magento\GraphQlResolverCache\Model\Resolver\Result\CacheKey\GenericFactorProviderInterface;

/**
 * The request path as a factor of the response cache id and of the resolver
 * result cache keys, so a response served from documents is never handed to
 * a request that asked for core, and the other way round.
 */
class ModeFactor implements CacheIdFactorProviderInterface, GenericFactorProviderInterface
{
    public function __construct(
        private readonly Mode $mode,
    ) {
    }

    public function getFactorName(): string
    {
        return 'CATALOG_STOREFRONT';
    }

    public function getFactorValue(ContextInterface $context): string
    {
        return $this->mode->name();
    }
}
