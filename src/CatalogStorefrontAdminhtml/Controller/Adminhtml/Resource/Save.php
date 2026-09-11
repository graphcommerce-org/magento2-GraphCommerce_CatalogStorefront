<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Resource;

use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface as Repository;
use GraphCommerce\CatalogStorefront\Model\Registry\Definition;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'GraphCommerce_CatalogStorefrontAdminhtml::manage';
    public function __construct(Context $context, private readonly Repository $repository, private readonly Definition $definition, private readonly DataPersistorInterface $persistor, private readonly LoggerInterface $logger)
    {
        parent::__construct($context);
    }
    public function execute()
    {
        $data = (array)$this->getRequest()->getPostValue();
        $kind = (string)($data['kind'] ?? '');
        $id = (int)($data['id'] ?? 0);
        try {
            $this->definition->table($kind);
            $row = $this->repository->save($kind, $data);
            $this->persistor->clear('catalog_resource_' . $kind . '_' . $id);
            $this->messageManager->addSuccessMessage(__('The catalog resource has been saved.'));
            return $this->resultRedirectFactory->create()->setPath('catalog_storefront/resource/edit', ['kind' => $kind,'id' => $row['id']]);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Catalog resource save failed.', ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('The resource could not be saved.'));
        }
        if (!in_array($kind, Definition::KINDS, true)) {
            return $this->resultRedirectFactory->create()->setPath('catalog_storefront/views/index');
        }
        $this->persistor->set('catalog_resource_' . $kind . '_' . $id, $data);
        return $this->resultRedirectFactory->create()->setPath('catalog_storefront/resource/edit', ['kind' => $kind,'id' => $id]);
    }
}
