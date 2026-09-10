<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Block\Adminhtml;

use GraphCommerce\CatalogStorefrontAdminhtml\Model\DerivedViews;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\ExplorerLink;
use Magento\Backend\Block\Template;

class Views extends Template
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Template\Context $context,
        private readonly DerivedViews $derivedViews,
        private readonly ExplorerLink $explorerLink,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /** @return array<int, array<string, mixed>> */
    public function views(): array
    {
        return $this->derivedViews->views();
    }

    /** @return array{available: bool, fallback: string, items: array<int, array{id: int, code: string, key: string}>} */
    public function groups(): array
    {
        return $this->derivedViews->groups();
    }

    /** @return array{available: bool, id?: string} */
    public function searchEngine(): array
    {
        return $this->derivedViews->searchEngine();
    }

    /** @return array<int, array{name: string, id: string, status: string, schedule: string, available: bool}> */
    public function indexers(): array
    {
        return $this->derivedViews->indexers();
    }

    public function explorerUrl(): ?string
    {
        return $this->explorerLink->url();
    }
}
