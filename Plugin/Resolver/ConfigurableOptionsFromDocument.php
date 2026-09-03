<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProductGraphQl\Model\Resolver\Options;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;

/**
 * Serves configurable_options from the optionsV2 slice of the document.
 *
 * The feed value id already is the core value uid ("configurable/<attribute
 * id>/<value index>"), so attribute ids come from decoding it. Swatch data
 * travels with the values and is handed to the swatch_data resolver through
 * SwatchDataFromDocument. Core returns the admin label as both default_label
 * and store_label, with use_default_value always true.
 *
 * The feed carries no super attribute id or use_default flag, so a selection
 * of those fields keeps the core resolver.
 */
class ConfigurableOptionsFromDocument
{
    public const SWATCH_KEY = '_gc_swatch';

    private const UNSERVED_OPTION_FIELDS = ['id', 'use_default'];

    public function __construct(
        private readonly Uid $uidEncoder,
        private readonly SwatchMedia $swatchMedia,
    ) {
    }

    public function aroundResolve(
        Options $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(ProductModelBuilder::DOCUMENT_KEY);
        if (!is_array($document)
            || ($value['type_id'] ?? null) !== Configurable::TYPE_CODE
            || array_intersect(array_keys($info->getFieldSelection(1)), self::UNSERVED_OPTION_FIELDS)
        ) {
            return $proceed($field, $context, $info, $value, $args);
        }

        $productId = (int)$document['productId'];
        $swatchMediaUrl = $this->swatchMedia->getSwatchMediaUrl();
        $options = [];
        foreach ((array)($document['optionsV2'] ?? []) as $option) {
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
                    // Core's option loader sets use_default_value to true for every value.
                    'use_default_value' => true,
                    'attribute_id' => $attributeId,
                    self::SWATCH_KEY => $swatch,
                ];
            }
            if ($attributeId === null) {
                return $proceed($field, $context, $info, $value, $args);
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
        // The core attribute collection has no ORDER BY, so its order changes
        // with the query plan. The merchant's position order is served instead.
        usort($options, static fn(array $a, array $b) =>
            [$a['position'], $a['attribute_id_v2']] <=> [$b['position'], $b['attribute_id_v2']]);

        return $options;
    }
}
