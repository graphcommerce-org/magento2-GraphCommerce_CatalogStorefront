<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Stores > Configuration > Catalog > Catalog > Catalog Storefront Search.
 */
class Config
{
    public const ENTITY_ID_SORT = 'catalog/storefront_search/entity_id_sort';
    public const RECORD_SEARCH_TERMS = 'catalog/storefront_search/record_search_terms';
    public const RESULT_WINDOW = 'catalog/storefront_search/result_window';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function entityIdSort(): bool
    {
        return $this->scopeConfig->isSetFlag(self::ENTITY_ID_SORT);
    }

    public function recordSearchTerms(): bool
    {
        return $this->scopeConfig->isSetFlag(self::RECORD_SEARCH_TERMS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * The number of hits a listing reads with one query; 0 leaves the engine's own limit.
     */
    public function resultWindow(): int
    {
        return (int)$this->scopeConfig->getValue(self::RESULT_WINDOW);
    }
}
