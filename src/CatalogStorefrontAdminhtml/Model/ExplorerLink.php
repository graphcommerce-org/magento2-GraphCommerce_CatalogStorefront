<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Model;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;

/** The optional existing local preview, subject to its own module and ACL. */
class ExplorerLink
{
    private const MODULE = 'MageOS_GraphQLAdminHtml';
    private const ACL = 'MageOS_GraphQLAdminHtml::graphql';

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly AuthorizationInterface $authorization,
        private readonly UrlInterface $url,
    ) {
    }

    public function url(): ?string
    {
        if (!$this->moduleManager->isEnabled(self::MODULE)
            || !$this->authorization->isAllowed(self::ACL)
        ) {
            return null;
        }

        return $this->url->getUrl('mageos_graphql/index/index');
    }
}
