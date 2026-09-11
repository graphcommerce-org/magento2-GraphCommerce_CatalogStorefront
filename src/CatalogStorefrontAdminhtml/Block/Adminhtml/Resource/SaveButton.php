<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Block\Adminhtml\Resource;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SaveButton implements ButtonProviderInterface
{
    public function __construct(private readonly AuthorizationInterface $authorization, private readonly \GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface $configuration)
    {
    }
    public function getButtonData(): array
    {
        if (!$this->configuration->capabilities()['can_manage'] || !$this->authorization->isAllowed('GraphCommerce_CatalogStorefrontAdminhtml::manage')) {
            return [];
        }
        return ['label' => __('Save'),'class' => 'save primary','sort_order' => 90,'data_attribute' => ['mage-init' => ['buttonAdapter' => ['actions' => [['targetName' => 'catalog_storefront_resource_form.catalog_storefront_resource_form','actionName' => 'save','params' => [true]]]]]]];
    }
}
