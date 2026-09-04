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
    public const RECORD_SEARCH_TERMS = 'catalog/storefront_documents/record_search_terms';
    public const REQUEST_OVERRIDE = 'catalog/storefront_documents/request_override';
    public const STRICT = 'catalog/storefront_documents/strict';

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
     * Whether a request may pick its own path with the X-Catalog-Storefront
     * header: `documents` or `core`. For test environments and the parity gate.
     */
    public function requestOverride(): bool
    {
        return $this->scopeConfig->isSetFlag(self::REQUEST_OVERRIDE);
    }

    /**
     * Whether a request served from documents reports every fallback to core
     * and every SQL statement in the response extensions. For test
     * environments only: the report exposes statements.
     */
    public function strict(): bool
    {
        return $this->scopeConfig->isSetFlag(self::STRICT);
    }

    /**
     * Whether a search records its term and popularity in the database.
     */
    public function recordSearchTerms(): bool
    {
        return $this->scopeConfig->isSetFlag(self::RECORD_SEARCH_TERMS, ScopeInterface::SCOPE_STORE);
    }
}
