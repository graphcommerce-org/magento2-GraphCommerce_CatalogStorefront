<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Block\Adminhtml\Resource;

use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface as Repository;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class DeleteButton implements ButtonProviderInterface
{
    public function __construct(private readonly UrlInterface $url, private readonly RequestInterface $request, private readonly AuthorizationInterface $authorization, private readonly Repository $repository)
    {
    }
    public function getButtonData(): array
    {
        $id = (int)$this->request->getParam('id');
        if (!$this->repository->capabilities()['can_manage'] || !$id || !$this->authorization->isAllowed('GraphCommerce_CatalogStorefrontAdminhtml::delete')) {
            return [];
        }
        $kind = (string)$this->request->getParam('kind');
        $row = $this->repository->get($kind, $id);
        $url = $this->url->getUrl('catalog_storefront/resource/delete', ['kind' => $kind,'id' => $id,'version' => $row['version']]);
        return ['label' => __('Delete'),'class' => 'delete','sort_order' => 20,'on_click' => 'deleteConfirm(' . json_encode((string)__('Delete this catalog resource?')) . ',' . json_encode($url) . ', {"data": {}})'];
    }
}
