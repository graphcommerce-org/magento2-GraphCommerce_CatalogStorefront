<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Ui\Component;

use Magento\Ui\Component\Listing\Columns;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;

class RegistryColumns extends Columns
{
    public function __construct(ContextInterface $context, private readonly UrlInterface $url, private readonly AuthorizationInterface $authorization, array $components = [], array $data = [])
    {
        parent::__construct($context, $components, $data);
    }
    public function prepare(): void
    {
        parent::prepare();
        $config = $this->getData('config');
        $kind = $config['dataset'] === 'catalogViews' ? 'views' : $config['dataset'];
        $config['actionUrl'] = $this->authorization->isAllowed('GraphCommerce_CatalogStorefrontAdminhtml::manage') ? $this->url->getUrl('catalog_storefront/resource/edit', ['kind' => $kind]) : '';
        if (!$config['actionUrl']) {
            $config['actionLabel'] = '';
        }
        $this->setData('config', $config);
    }
}
