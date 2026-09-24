<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Model;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;

/**
 * A product attribute collection that holds given attribute models and runs no query for them.
 */
class LoadedAttributeCollection extends Collection
{
    /**
     * @param Attribute[] $attributes
     */
    public function withItems(array $attributes): self
    {
        foreach ($attributes as $attribute) {
            $this->addItem($attribute);
        }
        $this->_setIsLoaded();

        return $this;
    }
}
