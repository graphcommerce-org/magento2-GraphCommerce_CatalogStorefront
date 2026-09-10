<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read;

/**
 * Expands the compact configurableOptions of a document into the super
 * attribute rows core's attribute collection yields: ids as the strings the
 * database returns, the admin label as both default_label and store_label,
 * use_default_value always true, the swatch as type and value.
 */
class ConfigurableOptions
{
    /**
     * @return array[]|null null when the document carries no configurableOptions, or carries them
     *                      without an attribute id or a value index
     */
    public function attributes(array $document): ?array
    {
        if (!isset($document['configurableOptions'], $document['productId'])) {
            return null;
        }
        $productId = (int)$document['productId'];
        $options = [];
        foreach ((array)$document['configurableOptions'] as $option) {
            // An option identifies its super attribute, and a value its index. Without either, the
            // whole set goes to core: a short option list renders a product that cannot be bought.
            if (!isset($option['attribute'])) {
                return null;
            }
            $attributeId = (int)$option['attribute'];
            $values = [];
            foreach ((array)($option['values'] ?? []) as $optionValue) {
                if (!isset($optionValue['index'])) {
                    return null;
                }
                $defaultLabel = $optionValue['defaultLabel'] ?? $optionValue['label'] ?? null;
                $values[] = [
                    'value_index' => (string)$optionValue['index'],
                    'label' => $optionValue['label'] ?? null,
                    'default_label' => $defaultLabel,
                    'store_label' => $defaultLabel,
                    'use_default_value' => true,
                    'attribute_id' => (string)$attributeId,
                    'swatch' => $optionValue['swatch'] ?? null,
                ];
            }
            $options[] = [
                'id' => $option['id'] ?? null,
                'use_default' => (bool)($option['useDefault'] ?? false),
                'attribute_id' => (string)$attributeId,
                'attribute_code' => $option['code'] ?? null,
                'label' => $option['label'] ?? null,
                'position' => (int)($option['position'] ?? 0),
                'product_id' => $productId,
                'values' => $values,
            ];
        }

        return $options;
    }
}
