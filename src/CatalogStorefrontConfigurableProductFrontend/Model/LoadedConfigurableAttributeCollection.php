<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Attribute\Collection;

/**
 * A configurable attribute collection that holds given super attribute models and runs no query
 * for them: the type core's getConfigurableAttributes() returns, so a plugin typed against core's
 * result keeps working when the attributes come from a document.
 */
class LoadedConfigurableAttributeCollection extends Collection
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
