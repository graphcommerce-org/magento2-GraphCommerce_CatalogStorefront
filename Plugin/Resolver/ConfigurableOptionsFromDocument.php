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
 * id>/<value index>"), so attribute ids come from decoding it. Color and image
 * swatches travel with the values and are handed to the swatch_data resolver
 * through SwatchDataFromDocument; the feed carries no textual swatch values,
 * so those keep the core resolver.
 *
 * The feed carries no super attribute id, use_default flags or admin labels,
 * so a selection of those fields keeps the core resolver.
 */
class ConfigurableOptionsFromDocument
{
    public const SWATCH_KEY = '_gc_swatch';

    private const UNSERVED_OPTION_FIELDS = ['id', 'use_default'];
    private const UNSERVED_VALUE_FIELDS = ['default_label', 'store_label', 'use_default_value'];

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
        $selection = $info->getFieldSelection(1);
        if (!is_array($document)
            || ($value['type_id'] ?? null) !== Configurable::TYPE_CODE
            || array_intersect(array_keys($selection), self::UNSERVED_OPTION_FIELDS)
            || array_intersect(array_keys((array)($selection['values'] ?? [])), self::UNSERVED_VALUE_FIELDS)
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
                // Core hands these through as the strings the database returns.
                $entry = [
                    'value_index' => $valueIndex,
                    'label' => $optionValue['label'] ?? null,
                    'attribute_id' => $attributeId,
                ];
                if (!empty($optionValue['colorHex'])) {
                    $entry[self::SWATCH_KEY] = ['type' => Swatch::SWATCH_TYPE_VISUAL_COLOR, 'value' => $optionValue['colorHex']];
                } elseif (!empty($optionValue['imageUrl'])) {
                    $file = substr((string)$optionValue['imageUrl'], strlen($swatchMediaUrl));
                    $entry[self::SWATCH_KEY] = [
                        'type' => Swatch::SWATCH_TYPE_VISUAL_IMAGE,
                        'value' => $file,
                        'thumbnail' => $this->swatchMedia->getSwatchAttributeImage(Swatch::SWATCH_THUMBNAIL_NAME, $file),
                    ];
                }
                $values[] = $entry;
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
        // The core attribute collection has no ORDER BY, so it lists the super
        // attributes in the (product_id, attribute_id) index order.
        usort($options, static fn(array $a, array $b) => $a['attribute_id_v2'] <=> $b['attribute_id_v2']);

        return $options;
    }
}
