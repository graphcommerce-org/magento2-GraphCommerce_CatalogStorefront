<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Whether the current request carries the configured storefront key. A
 * request with the key may pick its path and gets the fallback report.
 */
class StorefrontKey implements ResetAfterRequestInterface
{
    public const HEADER = 'X-Catalog-Storefront-Key';

    private ?bool $granted = null;

    public function __construct(
        private readonly Config $config,
        private readonly RequestInterface $request,
    ) {
    }

    public function granted(): bool
    {
        if ($this->granted === null) {
            $key = $this->config->key();
            $this->granted = $key !== '' && hash_equals($key, (string)$this->request->getHeader(self::HEADER));
        }

        return $this->granted;
    }

    public function _resetState(): void
    {
        $this->granted = null;
    }
}
