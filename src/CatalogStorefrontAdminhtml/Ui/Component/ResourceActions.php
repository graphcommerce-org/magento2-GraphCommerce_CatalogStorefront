<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Ui\Component;

use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Backend\Model\UrlInterface;

class ResourceActions extends Column
{
    public function __construct(ContextInterface $context, UiComponentFactory $uiComponentFactory, private readonly UrlInterface $url, private readonly \GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface $configuration, array $components = [], array $data = [])
    {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }
    public function prepareDataSource(array $dataSource): array
    {
        $kind = (string)$this->getData('config/resourceKind');
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$row) {
                $row[$this->getData('name')] = ['edit' => ['href' => $this->url->getUrl('catalog_storefront/resource/edit', ['kind' => $kind,'id' => $row['id']]),'label' => $this->configuration->capabilities()['can_manage'] ? __('Edit') : __('View')]];
            }
        }
        unset($row);
        return $dataSource;
    }
}
