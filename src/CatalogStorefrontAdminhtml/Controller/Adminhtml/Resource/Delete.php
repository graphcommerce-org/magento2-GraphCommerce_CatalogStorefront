<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Resource;

use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface as Repository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GraphCommerce_CatalogStorefrontAdminhtml::delete';
    public function __construct(Context $context, private readonly Repository $repository, private readonly LoggerInterface $logger)
    {
        parent::__construct($context);
    }
    public function execute()
    {
        try {
            $this->repository->delete((string)$this->getRequest()->getParam('kind'), (int)$this->getRequest()->getParam('id'), (int)$this->getRequest()->getParam('version'));
            $this->messageManager->addSuccessMessage(__('The catalog resource has been deleted.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Catalog resource delete failed.', ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('The resource could not be deleted.'));
        }
        return $this->resultRedirectFactory->create()->setPath('catalog_storefront/views/index');
    }
}
