<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read;

class Selections extends \Magento\Bundle\Model\ResourceModel\Selection\Collection
{
    public function load($printQuery = false, $logQuery = false)
    {
        return $this->_setIsLoaded();
    }

    public function getAllIds($limit = null, $offset = null)
    {
        return array_slice(array_map(static fn($item) => $item->getId(), array_values($this->_items)), $offset ?? 0, $limit);
    }

    public function getSize()
    {
        return count($this->_items);
    }
}
