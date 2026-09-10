<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Views;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'GraphCommerce_CatalogStorefrontAdminhtml::views';

    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend((string)__('Catalog Storefront Views'));

        return $page;
    }
}
