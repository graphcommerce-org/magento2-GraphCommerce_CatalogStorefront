<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeMetadata;
use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Model\FilterProductCustomAttribute;
use Magento\CatalogGraphQl\Model\Resolver\Product\ProductCustomAttributes;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Serves custom_attributesV2 from the document's raw attribute values and the
 * store view's attribute documents: the visible, non-static attributes the
 * product has a value for, by attribute id as core's attribute list comes
 * back, a select or multiselect as its selected options in option order.
 * A filter on a property the attribute documents do not carry falls back to
 * core, which also reports a filter that is not an attribute property.
 */
class CustomAttributesFromDocument implements ResetAfterRequestInterface
{
    /** @var array<string, array[]> the attribute list per store view and filter set, computed once per request */
    private array $lists = [];

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
        private readonly AttributeMetadata $attributeMetadata,
        private readonly FilterProductCustomAttribute $filterCustomAttribute,
        private readonly LoggerInterface $logger,
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
        $document = ($value['model'] ?? null)?->getData(ProductModelBuilder::DOCUMENT_KEY);
        if (!is_array($document) || !isset($document['customAttributes'])) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $filters = [];
        foreach ((array)($args['filters'] ?? []) as $filterField => $filterValue) {
            if (!isset(self::FILTERS[$filterField])) {
                return $proceed($field, $context, $info, $value, $args);
            }
            $filters[self::FILTERS[$filterField]] = (int)$filterValue;
        }

        try {
            $store = $context->getExtensionAttributes()->getStore();
            $attributes = $this->lists[$store->getCode() . json_encode($filters)] ??= $this->attributes($store->getCode(), $filters);
            if (!$attributes) {
                return $proceed($field, $context, $info, $value, $args);
            }

            $values = [];
            foreach ((array)$document['customAttributes'] as $entry) {
                $values[$entry['attributeCode']] = (string)($entry['value'] ?? '');
            }

            $items = [];
            foreach ($attributes as $code => $attribute) {
                if (!array_key_exists($code, $values)) {
                    continue;
                }
                $item = ['entity_type' => ProductAttributeInterface::ENTITY_TYPE_CODE, 'code' => $code, 'sort_order' => ''];
                if (in_array($attribute['frontendInput'] ?? '', self::SELECT_INPUTS, true)) {
                    $selected = explode(',', $values[$code]);
                    $item['selected_options'] = [];
                    foreach ((array)($attribute['options'] ?? []) as $option) {
                        if (in_array((string)$option['id'], $selected, true)) {
                            $item['selected_options'][] = ['value' => (string)$option['id'], 'label' => $option['label']];
                        }
                    }
                    $item[AttributeValueTypeFromDocument::KEY] = 'AttributeSelectedOptions';
                } else {
                    $item['value'] = $values[$code];
                    $item[AttributeValueTypeFromDocument::KEY] = 'AttributeValue';
                }
                $items[] = $item;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('catalog-storefront custom attributes fallback: ' . $e->getMessage());

            return $proceed($field, $context, $info, $value, $args);
        }

        return ['items' => $items, 'errors' => []];
    }

    /**
     * @return array[] attribute documents keyed by code, in the order core's attribute list has them
     */
    private function attributes(string $storeViewCode, array $filters): array
    {
        $attributes = array_filter(
            $this->attributeMetadata->all($storeViewCode),
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
        uasort($attributes, static fn(array $a, array $b) => (int)($a['attributeId'] ?? 0) <=> (int)($b['attributeId'] ?? 0));

        return $this->filterCustomAttribute->execute($attributes);
    }

    public function _resetState(): void
    {
        $this->lists = [];
    }
}
