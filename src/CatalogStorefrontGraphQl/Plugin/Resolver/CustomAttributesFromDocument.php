<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Model\FilterProductCustomAttribute;
use Magento\CatalogGraphQl\Model\Resolver\Product\ProductCustomAttributes;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Serves custom_attributesV2 from the document's raw attribute values and the
 * attribute documents of the codes the product has a value for: the visible,
 * non-static ones, by attribute id as core's attribute list comes back, a
 * select or multiselect as its selected options in option order.
 */
class CustomAttributesFromDocument
{
    private const SELECT_INPUTS = ['select', 'multiselect'];

    /** GraphQL filter field to attribute document key; is_filterable filters on the mode core stores. */
    private const FILTERS = [
        'is_comparable' => 'visibleInCompareList',
        'is_filterable' => 'filterableMode',
        'is_filterable_in_search' => 'filterableInSearch',
        'is_searchable' => 'searchable',
        'is_used_for_price_rules' => 'usedForRules',
        'is_visible_on_front' => 'visibleInSearch',
        'used_in_product_listing' => 'visibleInListing',
    ];

    public function __construct(
        private readonly AttributeDocuments $attributeDocuments,
        private readonly FilterProductCustomAttribute $filterCustomAttribute,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        ProductCustomAttributes $subject,
        \Closure $proceed,
        Field $field,
        ContextInterface $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(HydrationInterface::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($field, $context, $info, $value, $args);
        }
        if (!isset($document['customAttributes'])) {
            $this->strict->fallback(self::class, 'document without customAttributes');
        }
        $filters = [];
        foreach ((array)($args['filters'] ?? []) as $filterField => $filterValue) {
            if (!isset(self::FILTERS[$filterField])) {
                $this->strict->fallback(self::class, 'unsupported custom attribute filter');
            }
            $filters[self::FILTERS[$filterField]] = (int)$filterValue;
        }

        try {
            $storeCode = $context->getExtensionAttributes()->getStore()->getCode();
            $values = array_map(
                static fn($value): ?string => $value === null ? null : (string)$value,
                (array)$document['customAttributes']
            );
            $attributes = array_filter(
                $this->attributeDocuments->byCodes($storeCode, array_keys($values)),
                static function (array $attribute) use ($filters): bool {
                    if (empty($attribute['visible']) || ($attribute['dataType'] ?? '') === 'static') {
                        return false;
                    }
                    foreach ($filters as $key => $expected) {
                        if ((int)($attribute[$key] ?? 0) !== $expected) {
                            return false;
                        }
                    }

                    return true;
                }
            );
            if (!$attributes && $values && !$this->attributeDocuments->available($storeCode)) {
                $this->strict->fallback(self::class, 'no attribute documents for the store view');
            }
            uasort($attributes, static fn(array $a, array $b) => (int)($a['attributeId'] ?? 0) <=> (int)($b['attributeId'] ?? 0));

            $items = [];
            foreach ($this->filterCustomAttribute->execute($attributes) as $code => $attribute) {
                $item = ['entity_type' => ProductAttributeInterface::ENTITY_TYPE_CODE, 'code' => $code, 'sort_order' => ''];
                if (in_array($attribute['frontendInput'] ?? '', self::SELECT_INPUTS, true)) {
                    // Core's option list starts with the blank option, which an empty value selects, as does a null
                    // value of a select; a null value of a multiselect selects nothing.
                    $selected = $values[$code] === null
                        ? ($attribute['frontendInput'] === 'select' ? [''] : [])
                        : explode(',', $values[$code]);
                    $item['selected_options'] = in_array('', $selected, true) ? [['value' => '', 'label' => ' ']] : [];
                    foreach ((array)($attribute['options'] ?? []) as $option) {
                        if (in_array((string)$option['id'], $selected, true)) {
                            $item['selected_options'][] = ['value' => (string)$option['id'], 'label' => $option['label']];
                        }
                    }
                    $item[AttributeValueTypeFromDocument::KEY] = 'AttributeSelectedOptions';
                } else {
                    // Core answers an empty string for a null value: the field is not nullable.
                    $item['value'] = $values[$code] ?? '';
                    $item[AttributeValueTypeFromDocument::KEY] = 'AttributeValue';
                }
                $items[] = $item;
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        return ['items' => $items, 'errors' => []];
    }
}
