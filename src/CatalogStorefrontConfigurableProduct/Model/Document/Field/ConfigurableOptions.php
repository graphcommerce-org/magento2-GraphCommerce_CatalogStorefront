<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Document\Field;

use GraphCommerce\CatalogStorefrontApi\Document\ProductDocumentFieldInterface;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;

/**
 * Stores a configurable's options on its document in the compact form
 * Model\Read\ConfigurableOptions expands: per option the super attribute id, the attribute id and
 * code, the label, the position and the use-default flag; per value the
 * value index, the label, the admin label where it differs and the swatch.
 * Every uid and every repeated id is derived at read time. The feed value id
 * is the core value uid ("configurable/<attribute id>/<value index>"), so
 * the attribute id comes from decoding it. Swatch images travel as the
 * swatch file; the thumbnail variation is generated here and its URL is
 * built at read time, so the document carries no host. Core lists the
 * options in an undefined order (no ORDER BY); the merchant's position order
 * is used instead.
 */
class ConfigurableOptions implements ProductDocumentFieldInterface
{
    public function __construct(
        private readonly Uid $uidEncoder,
        private readonly SwatchMedia $swatchMedia,
    ) {
    }

    public function add(string $storeViewCode, array $documents): array
    {
        foreach ($documents as &$document) {
            if (($document['type'] ?? null) === 'configurable') {
                $document['configurableOptions'] = $this->build($document);
                // The configurable entries live on in the compact form; the raw ones were a third of the document.
                if ($document['configurableOptions'] !== null) {
                    $document['optionsV2'] = array_values(array_filter(
                        (array)($document['optionsV2'] ?? []),
                        static fn(array $option) => ($option['type'] ?? null) !== 'configurable'
                    ));
                }
            }
        }

        return $documents;
    }

    /**
     * @return array[]|null null when an option carries no value to decode an attribute id from
     */
    private function build(array $productRow): ?array
    {
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
