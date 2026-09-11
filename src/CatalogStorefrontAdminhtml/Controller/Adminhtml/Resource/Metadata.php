<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Resource;

use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface as Repository;
use GraphCommerce\CatalogStorefrontApi\Service\SourceMetadataInterface as SourceMetadata;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;

class Metadata extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GraphCommerce_CatalogStorefrontAdminhtml::views';
    public function __construct(Context $context, private readonly Repository $repository, private readonly SourceMetadata $metadata, private readonly JsonFactory $json) { parent::__construct($context); }
    public function execute()
    {
        try {
            $source = $this->repository->get('sources', (int)$this->getRequest()->getParam('source_id'));
            return $this->json->create()->setData($this->metadata->describe((int)$source['id']));
        } catch (LocalizedException $e) {
            return $this->json->create()->setHttpResponseCode(422)->setData(['error' => $e->getMessage()]);
        } catch (\Throwable) {
            return $this->json->create()->setHttpResponseCode(503)->setData(['error' => (string)__('Imported source metadata is currently unavailable.')]);
        }
    }
}
