<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Registry;

class Platform
{
    public function name(): string
    {
        return \Composer\InstalledVersions::isInstalled('mage-os/magento2-base')
            || \Composer\InstalledVersions::isInstalled('mage-os/product-community-edition') ? 'MageOS' : 'Magento';
    }
}
