<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Model\Read;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\Framework\GraphQl\Query\Uid;

/**
 * Expands the compact configurableOptions of a document into the
 * configurable_options response shape core's Options resolver returns. Core
 * hands ids through as the strings the database returns, answers the admin
 * label as both default_label and store_label, and use_default_value is
 * always true.
 */
class ConfigurableOptions
{
    public function __construct(
        private readonly Uid $uidEncoder,
    ) {
    }

    /**
     * @return array[]|null null when the document carries no configurableOptions
     */
    public function expand(array $document): ?array
    {
        if (!isset($document['configurableOptions'])) {
            return null;
        }
        $productId = (int)$document['productId'];
        $options = [];
        foreach ((array)$document['configurableOptions'] as $option) {
            $attributeId = (int)$option['attribute'];
            $values = [];
            foreach ((array)($option['values'] ?? []) as $optionValue) {
                $defaultLabel = $optionValue['defaultLabel'] ?? $optionValue['label'] ?? null;
                $values[] = [
                    'value_index' => (string)$optionValue['index'],
                    'label' => $optionValue['label'] ?? null,
                    'default_label' => $defaultLabel,
                    'store_label' => $defaultLabel,
                    'use_default_value' => true,
                    'attribute_id' => (string)$attributeId,
                    PrefillerInterface::KEY => [
                        'uid' => $this->uidEncoder->encode('configurable/' . $attributeId . '/' . $optionValue['index']),
                        'swatch_data' => $optionValue['swatch'] ?? null,
                    ],
                ];
            }
            $options[] = [
                'id' => $option['id'] ?? null,
                'use_default' => (bool)($option['useDefault'] ?? false),
                'uid' => $this->uidEncoder->encode('configurable/' . $productId . '/' . $attributeId),
                'attribute_id' => (string)$attributeId,
                'attribute_id_v2' => $attributeId,
                'attribute_uid' => $this->uidEncoder->encode((string)$attributeId),
                'attribute_code' => $option['code'] ?? null,
                'label' => $option['label'] ?? null,
                'position' => (int)($option['position'] ?? 0),
                'product_id' => $productId,
                'product_uid' => $this->uidEncoder->encode((string)$productId),
                'values' => $values,
            ];
        }

        return $options;
    }
}
