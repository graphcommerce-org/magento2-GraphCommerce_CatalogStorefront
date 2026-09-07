<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The path of the current request: documents or core. The store view's
 * Serve GraphQL setting decides, unless the request carries the storefront
 * key and the X-Catalog-Storefront header names a path.
 */
class Mode implements ResetAfterRequestInterface
{
    public const HEADER = 'X-Catalog-Storefront';
    public const DOCUMENTS = 'documents';
    public const CORE = 'core';

    private ?bool $documents = null;

    public function __construct(
        private readonly Config $config,
        private readonly StorefrontKey $key,
        private readonly RequestInterface $request,
    ) {
    }

    public function documents(): bool
    {
        if ($this->documents === null) {
            $header = $this->key->granted() ? strtolower((string)$this->request->getHeader(self::HEADER)) : '';
            $this->documents = match ($header) {
                self::DOCUMENTS => true,
                self::CORE => false,
                default => $this->config->serveGraphQl(),
            };
        }

        return $this->documents;
    }

    public function name(): string
    {
        return $this->documents() ? self::DOCUMENTS : self::CORE;
    }

    public function _resetState(): void
    {
        $this->documents = null;
    }
}
