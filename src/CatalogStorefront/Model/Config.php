<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const SERVE_READS = 'graphcommerce/catalog_storefront/serve_reads';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Whether catalog reads are served from documents in the current store view.
     */
    public function serveReads(): bool
    {
        return $this->scopeConfig->isSetFlag(self::SERVE_READS, ScopeInterface::SCOPE_STORE);
    }
}
