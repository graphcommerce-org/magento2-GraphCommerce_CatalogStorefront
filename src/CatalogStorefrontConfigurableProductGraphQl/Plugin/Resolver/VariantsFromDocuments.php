<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProductGraphQl\Model\Resolver\ConfigurableVariant;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Serves a configurable product's variants from the documents of its
 * children: the enabled ones, in stock unless out-of-stock products are
 * shown, by id as the core child collection returns them. The variant
 * attributes resolve from the option values the document carries, keyed by
 * attribute id in the order core's super attribute index yields.
 */
class VariantsFromDocuments
{
    public function __construct(
        private readonly HydrationInterface $hydration,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        ConfigurableVariant $subject,
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

        try {
            $store = $context->getExtensionAttributes()->getStore();
            $showOutOfStock = $this->stockConfiguration->isShowOutOfStock((int)$store->getId());
            $children = array_filter(
                $this->hydration->documents($store->getCode(), array_values((array)($document['variantIds'] ?? []))),
                static fn(array $child) => ($child['status'] ?? null) === 'Enabled'
                    && ($showOutOfStock || ($child['stock']['isSalable'] ?? $child['inStock'] ?? false))
            );
            ksort($children);

            $options = [];
            foreach ((array)($document['configurableOptions'] ?? []) as $option) {
                $map = [];
                foreach ((array)($option['values'] ?? []) as $optionValue) {
                    $map[$option['attribute_id'] . ':' . $optionValue['value_index']] = $optionValue;
                }
                $options[(int)$option['attribute_id']] = [
                    'attribute_code' => $option['attribute_code'],
                    'attribute_id' => $option['attribute_id'],
                    'options_map' => $map,
                ];
            }
            ksort($options);

            $variants = [];
            $requestedFields = array_keys((array)($info->getFieldSelection(1)['product'] ?? []));
            foreach ($this->hydration->models($store, $context, $children, $requestedFields) as $model) {
                $variants[] = [
                    'sku' => $model->getSku(),
                    'product' => ['model' => $model, 'sku' => $model->getSku()],
                    'options' => $options,
                ];
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);

            return $proceed($field, $context, $info, $value, $args);
        }

        return $variants;
    }
}
