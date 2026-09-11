<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Block\Adminhtml\Resource;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class BackButton implements ButtonProviderInterface
{
    public function __construct(private readonly UrlInterface $url)
    {
    }
    public function getButtonData(): array
    {
        return ['label' => __('Back'),'class' => 'back','sort_order' => 10,'on_click' => 'location.href=' . json_encode($this->url->getUrl('catalog_storefront/views/index'), JSON_HEX_APOS | JSON_HEX_QUOT)];
    }
}
