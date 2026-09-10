<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Model;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;

/** Links to existing Magento administration surfaces, subject to their ACLs. */
class AdminLinks
{
    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $url,
    ) {
    }

    public function stores(): ?string
    {
        return $this->allowed('Magento_Backend::store', 'adminhtml/system_store/index');
    }

    public function configuration(): ?string
    {
        return $this->allowed('Magento_Catalog::config_catalog', 'adminhtml/system_config/edit', ['section' => 'catalog']);
    }

    public function indexers(): ?string
    {
        return $this->allowed('Magento_Indexer::index', 'indexer/indexer/list');
    }

    /** @param array<string, string> $parameters */
    private function allowed(string $resource, string $route, array $parameters = []): ?string
    {
        return $this->authorization->isAllowed($resource) ? $this->url->getUrl($route, $parameters) : null;
    }
}
