<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read;

class Options extends \Magento\Bundle\Model\ResourceModel\Option\Collection
{
    public function load($printQuery = false, $logQuery = false)
    {
        return $this->_setIsLoaded();
    }

    public function getAllIds()
    {
        return array_keys($this->_items);
    }

    public function getSize()
    {
        return count($this->_items);
    }
}
