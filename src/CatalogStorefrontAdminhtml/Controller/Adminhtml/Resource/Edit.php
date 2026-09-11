<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Resource;

use GraphCommerce\CatalogStorefront\Model\Registry\Definition;
use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface as Repository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Exception\LocalizedException;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GraphCommerce_CatalogStorefrontAdminhtml::views';
    public function __construct(Context $context, private readonly Definition $definition, private readonly Repository $repository, private readonly PageFactory $pages)
    {
        parent::__construct($context);
    }
    public function execute()
    {
        try {
            $kind = (string)$this->getRequest()->getParam('kind');
            $this->definition->table($kind);
            $id = (int)$this->getRequest()->getParam('id', 0);
            $row = $id ? $this->repository->get($kind, $id) : null;
            $this->messageManager->addNoticeMessage($this->repository->capabilities()['message']);
            $page = $this->pages->create();
            $page->setActiveMenu(self::ADMIN_RESOURCE);
            $page->getConfig()->getTitle()->prepend($row ? $row['name'] : __('New %1', Definition::LABELS[$kind]));
            return $page;
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->resultRedirectFactory->create()->setPath('catalog_storefront/views/index');
        }
    }
}
