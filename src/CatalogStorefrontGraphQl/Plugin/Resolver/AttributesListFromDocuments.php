<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use Magento\EavGraphQl\Model\Resolver\AttributesList;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\EnumLookup;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * The product attributes list from the attribute documents of the store view: one read of the
 * index instead of the attribute repository with its label join and an option load per select
 * attribute. Core serves other entity types.
 */
class AttributesListFromDocuments
{
    private const ENTITY = 'catalog_product';

    // GraphQL flag to document field; is_filterable reads filterableMode, since core matches mode 1 only
    private const FLAGS = [
        'is_comparable' => 'visibleInCompareList',
        'is_filterable_in_search' => 'filterableInSearch',
        'is_html_allowed_on_front' => 'htmlAllowedOnFront',
        'is_searchable' => 'searchable',
        'is_used_for_price_rules' => 'usedForRules',
        'is_used_for_promo_rules' => 'usedForPromoRules',
        'is_visible_in_advanced_search' => 'visibleInAdvancedSearch',
        'is_visible_on_front' => 'visibleInSearch',
        'is_wysiwyg_enabled' => 'wysiwygEnabled',
        'used_in_product_listing' => 'visibleInListing',
    ];

    public function __construct(
        private readonly AttributeDocuments $attributeDocuments,
        private readonly EnumLookup $enumLookup,
        private readonly Mode $mode,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        AttributesList $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->mode->documents() || strtolower((string)($args['entityType'] ?? '')) !== self::ENTITY) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $filters = [];
        foreach ((array)($args['filters'] ?? []) as $name => $wanted) {
            if ($name !== 'is_filterable' && !isset(self::FLAGS[$name])) {
                $this->strict->fallback(self::class, 'unsupported attribute filter');
            }
            $filters[$name === 'is_filterable' ? 'filterableMode' : self::FLAGS[$name]] = (int)$wanted;
        }
        $documents = $this->attributeDocuments->all($context->getExtensionAttributes()->getStore()->getCode());
        if (!$documents) {
            $this->strict->fallback(self::class, 'no attribute documents for the store view');
        }

        $items = [];
        foreach ($documents as $document) {
            if (empty($document['isVisible']) || ($document['backendType'] ?? '') === 'static' || ($document['attributeType'] ?? self::ENTITY) !== self::ENTITY) {
                continue;
            }
            foreach ($filters as $key => $wanted) {
                if ((int)($document[$key] ?? 0) !== $wanted) {
                    continue 2;
                }
            }
            $default = (string)($document['defaultValue'] ?? '');
            $options = [];
            foreach ((array)($document['options'] ?? []) as $option) {
                $optionValue = (string)($option['id'] ?? '');
                $label = (string)($option['label'] ?? '');
                if (trim($optionValue) === '' && trim($label) === '') {
                    continue;
                }
                $options[] = ['label' => $label, 'value' => $optionValue, 'is_default' => $default && in_array($optionValue, explode(',', $default), true)];
            }
            $input = $document['frontendInput'] ?? null;
            $item = [
                'id' => $document['attributeId'] ?? null,
                'code' => $document['attributeCode'] ?? $document['id'],
                'label' => $document['label'] ?? null,
                'sort_order' => $document['position'] ?? null,
                'entity_type' => 'CATALOG_PRODUCT',
                'frontend_input' => $input === null ? 'UNDEFINED' : $this->enumLookup->getEnumValueFromField('AttributeFrontendInputEnum', (string)$input),
                'frontend_class' => $document['frontendClass'] ?? null,
                'is_required' => !empty($document['required']),
                'default_value' => $document['defaultValue'] ?? null,
                'is_unique' => !empty($document['unique']),
                'options' => $options,
                'is_filterable' => (int)($document['filterableMode'] ?? 0) === 1,
                'apply_to' => empty($document['applyTo']) ? null : array_map('strtoupper', explode(',', (string)$document['applyTo'])),
            ];
            foreach (self::FLAGS as $flag => $key) {
                $item[$flag] = !empty($document[$key]);
            }
            $items[] = $item + array_map('strtoupper', (array)json_decode((string)($document['additionalData'] ?? ''), true));
        }
        usort($items, static fn(array $a, array $b) => (int)$a['id'] <=> (int)$b['id']);

        return ['items' => $items, 'entity_type' => 'CATALOG_PRODUCT', 'errors' => []];
    }
}
