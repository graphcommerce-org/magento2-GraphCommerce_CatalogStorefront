<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Model\Read;

use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read\ConfigurableOptions as Attributes;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\Framework\GraphQl\Query\Uid;

/**
 * The configurable_options response shape core's Options resolver returns:
 * the super attribute rows of the document with every uid, and the value
 * uid and swatch pre-filled.
 */
class ConfigurableOptions
{
    public function __construct(
        private readonly Attributes $attributes,
        private readonly Uid $uidEncoder,
    ) {
    }

    /**
     * @return array[]|null null when the document carries no configurableOptions
     */
    public function expand(array $document): ?array
    {
        $options = $this->attributes->attributes($document);
        if ($options === null) {
            return null;
        }
        foreach ($options as &$option) {
            $attributeId = $option['attribute_id'];
            foreach ($option['values'] as &$value) {
                $swatch = $value['swatch'];
                unset($value['swatch']);
                $value[PrefillerInterface::KEY] = [
                    'uid' => $this->uidEncoder->encode('configurable/' . $attributeId . '/' . $value['value_index']),
                    'swatch_data' => $swatch,
                ];
            }
            unset($value);
            $option = [
                'id' => $option['id'],
                'use_default' => $option['use_default'],
                'uid' => $this->uidEncoder->encode('configurable/' . $option['product_id'] . '/' . $attributeId),
                'attribute_id' => $attributeId,
                'attribute_id_v2' => (int)$attributeId,
                'attribute_uid' => $this->uidEncoder->encode($attributeId),
                'attribute_code' => $option['attribute_code'],
                'label' => $option['label'],
                'position' => $option['position'],
                'product_id' => $option['product_id'],
                'product_uid' => $this->uidEncoder->encode((string)$option['product_id']),
                'values' => $option['values'],
            ];
        }

        return $options;
    }
}
