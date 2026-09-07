<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The document store settings, Stores > Configuration > Catalog > Catalog >
 * Catalog Storefront Document Store.
 */
class Config
{
    public const INDEX_ENABLED = 'catalog/storefront_documents/index_enabled';
    public const SERVE_GRAPHQL = 'catalog/storefront_documents/serve_graphql';
    public const KEY = 'catalog/storefront_documents/key';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Whether the feeds are written into the document store as they export.
     */
    public function indexing(): bool
    {
        return $this->scopeConfig->isSetFlag(self::INDEX_ENABLED);
    }

    /**
     * Whether catalog GraphQL reads are served from documents in the current store view.
     */
    public function serveGraphQl(): bool
    {
        return $this->scopeConfig->isSetFlag(self::SERVE_GRAPHQL, ScopeInterface::SCOPE_STORE);
    }

    /**
     * The key a request sends in the X-Catalog-Storefront-Key header to pick
     * its path and get the fallback report; empty until the configuration is
     * saved once.
     */
    public function key(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::KEY));
    }

}
