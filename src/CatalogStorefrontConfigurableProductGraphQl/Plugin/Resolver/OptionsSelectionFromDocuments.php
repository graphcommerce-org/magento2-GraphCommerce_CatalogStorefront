<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Model\Read\ConfigurableOptions;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProductGraphQl\Model\Options\SelectionUidFormatter;
use Magento\ConfigurableProductGraphQl\Model\Resolver\OptionsSelectionMetadata;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Serves configurable_product_options_selection from the documents of the
 * salable variants (enabled, in stock, without required customizable
 * options) and the parent's option slice: the options still open with the
 * availability of every value, the values still selectable per attribute,
 * the single variant the selection narrows to, and the media gallery entries
 * of the variants that match, as core assembles them.
 */
class OptionsSelectionFromDocuments
{
    public function __construct(
        private readonly HydrationInterface $hydration,
        private readonly SelectionUidFormatter $selectionUidFormatter,
        private readonly Uid $uidEncoder,
        private readonly Strict $strict,
        private readonly ConfigurableOptions $configurableOptions,
    ) {
    }

    public function aroundResolve(
        OptionsSelectionMetadata $subject,
        \Closure $proceed,
        Field $field,
        ContextInterface $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(HydrationInterface::DOCUMENT_KEY);
        if (!is_array($document) || ($value['type_id'] ?? null) !== Configurable::TYPE_CODE) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $options = (array)$this->configurableOptions->expand($document);
        if (!$options || !isset($options[0]['id'])) {
            $this->strict->fallback(self::class, 'configurableOptions without option ids');
            return $proceed($field, $context, $info, $value, $args);
        }

        try {
            $store = $context->getExtensionAttributes()->getStore();
            $selected = $this->selectionUidFormatter->extract((array)($args['configurableOptionValueUids'] ?? []));
            $variants = array_filter(
                $this->hydration->documents($store->getCode(), array_values((array)($document['variantIds'] ?? []))),
                static fn(array $child) => ($child['status'] ?? null) === 'Enabled'
                    && ($child['stock']['isSalable'] ?? $child['inStock'] ?? false)
                    && empty($child['shopperInputOptions'])
            );
            ksort($variants);

            // Per attribute id, the variant ids per value; per variant id, its value per attribute.
            $index = [];
            $byValue = [];
            foreach ($variants as $variantId => $variant) {
                $values = [];
                foreach ((array)($variant['customAttributes'] ?? []) as $attribute) {
                    $values[$attribute['attributeCode']] = $attribute['value'] ?? null;
                }
                foreach ($options as $option) {
                    $attributeValue = $values[$option['attribute_code']] ?? null;
                    if ($attributeValue !== null) {
                        $byValue[(int)$option['attribute_id']][(int)$attributeValue][] = $variantId;
                    }
                    $index[$variantId][(int)$option['attribute_id']] = $attributeValue;
                }
            }
            foreach ($selected as $attributeId => $valueIndex) {
                if (!isset($byValue[$attributeId][$valueIndex])) {
                    throw new GraphQlInputException(__('configurableOptionValueUids values are incorrect'));
                }
            }

            $configurableOptions = [];
            foreach ($options as $option) {
                $attributeId = (int)$option['attribute_id'];
                if (isset($selected[$attributeId])) {
                    continue;
                }
                $optionValues = [];
                foreach ((array)($option['values'] ?? []) as $optionValue) {
                    $valueIndex = (int)$optionValue['value_index'];
                    $optionValues[] = [
                        'uid' => $this->selectionUidFormatter->encode($attributeId, $valueIndex),
                        'is_available' => !empty($byValue[$attributeId][$valueIndex]),
                        'is_use_default' => (bool)($option['use_default'] ?? false),
                        'label' => $optionValue['label'],
                        'value_index' => $optionValue['value_index'],
                    ];
                }
                $configurableOptions[] = [
                    'uid' => $this->uidEncoder->encode((string)$option['id']),
                    'attribute_code' => $option['attribute_code'],
                    'label' => $option['label'],
                    'values' => $optionValues,
                ];
            }

            $availableSelections = [];
            $availableProducts = [];
            $matching = [];
            foreach ($index as $variantId => $variantValues) {
                foreach ($selected as $attributeId => $valueIndex) {
                    if ((int)($variantValues[$attributeId] ?? 0) !== $valueIndex) {
                        continue 2;
                    }
                }
                $availableProducts[] = $variantId;
                foreach ($variantValues as $attributeId => $attributeValue) {
                    $uid = $this->selectionUidFormatter->encode($attributeId, (int)$attributeValue);
                    if (!in_array($uid, $availableSelections[$attributeId]['option_value_uids'] ?? [], true)) {
                        $availableSelections[$attributeId]['option_value_uids'][] = $uid;
                        $availableSelections[$attributeId]['attribute_code'] = $this->attributeCode($options, $attributeId);
                    }
                }
                if (count($selected) === count($variantValues)) {
                    $matching[] = $variantId;
                }
            }

            $variant = null;
            $requestedFields = array_keys((array)($info->getFieldSelection(1)['variant'] ?? []));
            $galleryProducts = $this->hydration->models(
                $store,
                $context,
                array_intersect_key($variants, array_flip($availableProducts)),
                $requestedFields
            );
            if (count($availableProducts) === 1 && $matching) {
                $model = $galleryProducts[$availableProducts[0]];
                $variant = $model->getData() + ['url_path' => $variants[$availableProducts[0]]['url'] ?? null, 'model' => $model];
            }

            $mediaGallery = [];
            foreach ($galleryProducts as $model) {
                foreach ((array)($model->getData('media_gallery')['images'] ?? []) as $entry) {
                    $entryKey = 'image_' . $entry['file'] . '_' . $entry['position'];
                    $mediaGallery[$entryKey] = $entry + ['model' => $model];
                }
            }
        } catch (GraphQlInputException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);

            return $proceed($field, $context, $info, $value, $args);
        }

        return [
            'configurable_options' => $configurableOptions,
            'variant' => $variant,
            'model' => $value['model'],
            'options_available_for_selection' => array_values($availableSelections),
            'availableSelectionProducts' => $availableProducts,
            PrefillerInterface::KEY => ['media_gallery' => array_values($mediaGallery)],
        ];
    }

    private function attributeCode(array $options, int $attributeId): string
    {
        foreach ($options as $option) {
            if ((int)$option['attribute_id'] === $attributeId) {
                return (string)$option['attribute_code'];
            }
        }

        return '';
    }
}
