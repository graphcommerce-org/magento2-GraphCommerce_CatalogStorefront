<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed;

use GraphCommerce\CatalogStorefront\Plugin\Resolver\ConfigurableOptionsFromDocument;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;

/**
 * Builds the configurable_options response shape from the optionsV2 slice at
 * index time, so a request returns it as is. The feed value id already is the
 * core value uid ("configurable/<attribute id>/<value index>"), so attribute
 * ids come from decoding it. Swatch data travels with the values and is handed
 * to the swatch_data resolver through SwatchDataFromDocument. Core returns the
 * admin label as both default_label and store_label, with use_default_value
 * always true, and lists the options in an undefined order (no ORDER BY); the
 * merchant's position order is used instead.
 */
class ConfigurableOptionsBuilder
{
    public function __construct(
        private readonly Uid $uidEncoder,
        private readonly SwatchMedia $swatchMedia,
    ) {
    }

    /**
     * @return array[]|null null when an option carries no value to decode an attribute id from
     */
    public function build(array $productRow): ?array
    {
        $productId = (int)$productRow['productId'];
        $swatchMediaUrl = $this->swatchMedia->getSwatchMediaUrl();
        $options = [];
        foreach ((array)($productRow['optionsV2'] ?? []) as $option) {
            if (($option['type'] ?? null) !== 'configurable') {
                continue;
            }
            $attributeId = null;
            $values = [];
            foreach ((array)($option['values'] ?? []) as $optionValue) {
                [, $attributeId, $valueIndex] = explode('/', $this->uidEncoder->decode((string)$optionValue['id']));
                $swatch = null;
                if (!empty($optionValue['colorHex'])) {
                    $swatch = ['type' => Swatch::SWATCH_TYPE_VISUAL_COLOR, 'value' => $optionValue['colorHex']];
                } elseif (!empty($optionValue['imageUrl'])) {
                    $file = substr((string)$optionValue['imageUrl'], strlen($swatchMediaUrl));
                    $swatch = [
                        'type' => Swatch::SWATCH_TYPE_VISUAL_IMAGE,
                        'value' => $file,
                        'thumbnail' => $this->swatchMedia->getSwatchAttributeImage(Swatch::SWATCH_THUMBNAIL_NAME, $file),
                    ];
                } elseif (isset($optionValue['textSwatchValue'])) {
                    $swatch = ['type' => Swatch::SWATCH_TYPE_TEXTUAL, 'value' => $optionValue['textSwatchValue']];
                }
                // Core hands ids through as the strings the database returns.
                $values[] = [
                    'value_index' => $valueIndex,
                    'label' => $optionValue['label'] ?? null,
                    'default_label' => $optionValue['defaultLabel'] ?? $optionValue['label'] ?? null,
                    'store_label' => $optionValue['defaultLabel'] ?? $optionValue['label'] ?? null,
                    'use_default_value' => true,
                    'attribute_id' => $attributeId,
                    ConfigurableOptionsFromDocument::SWATCH_KEY => $swatch,
                ];
            }
            if ($attributeId === null) {
                return null;
            }
            $options[] = [
                'uid' => $this->uidEncoder->encode('configurable/' . $productId . '/' . $attributeId),
                'attribute_id' => (string)$attributeId,
                'attribute_id_v2' => (int)$attributeId,
                'attribute_uid' => $this->uidEncoder->encode((string)$attributeId),
                'attribute_code' => $option['id'],
                'label' => $option['label'] ?? null,
                'position' => (int)($option['sortOrder'] ?? 0),
                'product_id' => $productId,
                'product_uid' => $this->uidEncoder->encode((string)$productId),
                'values' => $values,
            ];
        }
        usort($options, static fn(array $a, array $b) =>
            [$a['position'], $a['attribute_id_v2']] <=> [$b['position'], $b['attribute_id_v2']]);

        return $options;
    }
}
