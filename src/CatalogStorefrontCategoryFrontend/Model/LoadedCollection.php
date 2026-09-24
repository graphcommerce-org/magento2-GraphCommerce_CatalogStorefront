<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Model;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;

/**
 * A category collection that holds built models and runs no query for them.
 */
class LoadedCollection extends Collection
{
    /**
     * @param Category[] $categories
     */
    public function withItems(array $categories): self
    {
        foreach ($categories as $category) {
            $this->addItem($category);
        }
        $this->_setIsLoaded();

        return $this;
    }
}
