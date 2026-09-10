<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The path of the current page: documents or core. Each rendered surface has its
 * own store view setting, unless the request carries the storefront key and the
 * X-Catalog-Storefront header names a path, which switches every surface at once.
 *
 * The same header the GraphQL side reads, so one request switches both.
 */
class Mode
{
    public const HEADER = 'X-Catalog-Storefront';
    public const DOCUMENTS = 'documents';
    public const CORE = 'core';

    public const SERVE_PLP = 'catalog/storefront_documents/serve_plp';
    public const SERVE_PDP = 'catalog/storefront_documents/serve_pdp';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Config $config,
        private readonly RequestInterface $request,
    ) {
    }

    /**
     * Whether a category or search listing builds its products from documents.
     */
    public function listing(int $storeId): bool
    {
        return $this->fromDocuments(self::SERVE_PLP, $storeId);
    }

    /**
     * Whether a product detail page takes its product from a document.
     */
    public function detail(int $storeId): bool
    {
        return $this->fromDocuments(self::SERVE_PDP, $storeId);
    }

    /**
     * Nothing here is memoised. This object outlives a request in a worker, and a
     * decision that depends on the request's own headers must not survive it: a
     * cached answer from the first request the worker happened to serve would then
     * bind every later one. The reads it makes are a config flag and a header.
     */
    private function fromDocuments(string $setting, int $storeId): bool
    {
        return match ($this->requested()) {
            self::DOCUMENTS => true,
            self::CORE => false,
            default => $this->scopeConfig->isSetFlag($setting, ScopeInterface::SCOPE_STORE, $storeId),
        };
    }

    /**
     * The path this request asked for by header, or null if it asked for none.
     * The page cache keys on it so the two paths never share a slot.
     */
    public function requested(): ?string
    {
        // A console request has no headers at all, and a listing collection can be
        // loaded from one.
        if (!$this->request instanceof HttpRequest) {
            return null;
        }
        $key = $this->config->key();
        if ($key === '' || !hash_equals($key, (string)$this->request->getHeader(StorefrontKey::HEADER))) {
            return null;
        }
        $header = strtolower((string)$this->request->getHeader(self::HEADER));

        return in_array($header, [self::DOCUMENTS, self::CORE], true) ? $header : null;
    }
}
