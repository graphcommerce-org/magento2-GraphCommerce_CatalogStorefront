<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\DataExporter\Provider;

use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProductDataExporter\Model\Provider\Product\Options;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;

/**
 * Exports a configurable's options in the compact form
 * Model\Read\ConfigurableOptions expands: per option the super attribute id,
 * the attribute id and code, the label, the position and the use-default
 * flag; per value the value index, the label, the admin label where it
 * differs and the swatch. Every uid and every repeated id is derived at read
 * time. The feed value id is the core value uid
 * ("configurable/<attribute id>/<value index>"), so the attribute id comes
 * from decoding it. Swatch images travel as the swatch file; the thumbnail
 * variation is generated here and its URL is built at read time, so the
 * document carries no host. Core lists the options in an undefined order (no
 * ORDER BY); the merchant's position order is used instead. The exporter's
 * own option provider yields the raw options, which
 * `Plugin\DataExporter\OptionsV2WithoutConfigurable` keeps out of `optionsV2`.
 */
class ConfigurableOptions
{
    public function __construct(
        private readonly Options $options,
        private readonly Uid $uidEncoder,
        private readonly SwatchMedia $swatchMedia,
    ) {
    }

    public function get(array $values): array
    {
        $rawOptions = [];
        foreach ($this->options->get($values) as $row) {
            $rawOptions[$row['storeViewCode']][(int)$row['productId']][] = $row['optionsV2'];
        }
        $output = [];
        foreach ($values as $value) {
            if (($value['type'] ?? null) !== Configurable::TYPE_CODE) {
                continue;
            }
            $options = $this->build($rawOptions[$value['storeViewCode']][(int)$value['productId']] ?? []);
            if ($options === null) {
                continue;
            }
            foreach ($options as $option) {
                $output[$value['storeViewCode'] . '_' . $value['productId'] . '_' . $option['attribute']] = [
                    'productId' => $value['productId'],
                    'storeViewCode' => $value['storeViewCode'],
                    'configurableOptions' => $option,
                ];
            }
        }

        return $output;
    }

    /**
     * @param array[] $rawOptions the exporter's own option entries of one product and store view
     * @return array[]|null null when an option carries no value to decode an attribute id from
     */
    private function build(array $rawOptions): ?array
    {
        $swatchMediaUrl = $this->swatchMedia->getSwatchMediaUrl();
        $options = [];
        foreach ($rawOptions as $option) {
            $attributeId = null;
            $values = [];
            foreach ((array)($option['values'] ?? []) as $optionValue) {
                [, $attributeId, $valueIndex] = explode('/', $this->uidEncoder->decode((string)$optionValue['id']));
                $swatch = null;
                if (!empty($optionValue['colorHex'])) {
                    $swatch = ['type' => Swatch::SWATCH_TYPE_VISUAL_COLOR, 'value' => $optionValue['colorHex']];
                } elseif (!empty($optionValue['imageUrl'])) {
                    $file = substr((string)$optionValue['imageUrl'], strlen($swatchMediaUrl));
                    $this->swatchMedia->getSwatchAttributeImage(Swatch::SWATCH_THUMBNAIL_NAME, $file);
                    $swatch = ['type' => Swatch::SWATCH_TYPE_VISUAL_IMAGE, 'value' => $file];
                } elseif (isset($optionValue['textSwatchValue'])) {
                    $swatch = ['type' => Swatch::SWATCH_TYPE_TEXTUAL, 'value' => $optionValue['textSwatchValue']];
                }
                $label = $optionValue['label'] ?? null;
                $defaultLabel = $optionValue['defaultLabel'] ?? $label;
                $values[] = ['index' => (int)$valueIndex, 'label' => $label]
                    + ($defaultLabel !== $label ? ['defaultLabel' => $defaultLabel] : [])
                    + ($swatch !== null ? ['swatch' => $swatch] : []);
            }
            if ($attributeId === null) {
                return null;
            }
            $options[] = [
                'id' => isset($option['superAttributeId']) ? (int)$option['superAttributeId'] : null,
                'attribute' => (int)$attributeId,
                'code' => $option['id'],
                'label' => $option['label'] ?? null,
                'position' => (int)($option['sortOrder'] ?? 0),
                'useDefault' => (bool)($option['useDefault'] ?? false),
                'values' => $values,
            ];
        }
        usort($options, static fn(array $a, array $b) =>
            [$a['position'], $a['attribute']] <=> [$b['position'], $b['attribute']]);

        return $options;
    }
}
