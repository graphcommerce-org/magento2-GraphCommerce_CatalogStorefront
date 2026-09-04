<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\AttributeOptionProvider;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Serves the facet attribute and option labels from the attribute documents,
 * shaped as core's provider returns them: an attribute is listed when one of
 * the requested option ids is its own, when it is filterable without results
 * (then with every option), or when it is a requested boolean or price
 * attribute. Falls back to core while the store view has no attribute
 * documents.
 */
class AttributeOptionsFromDocuments
{
    private const FILTERABLE_WITHOUT_RESULTS = 2;

    public function __construct(
        private readonly AttributeDocuments $attributeDocuments,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function aroundGetOptions(
        AttributeOptionProvider $subject,
        \Closure $proceed,
        array $optionIds,
        ?int $storeId,
        array $attributeCodes = []
    ): array {
        if (!$optionIds) {
            return [];
        }
        $attributes = $this->attributeDocuments->all($this->storeManager->getStore($storeId)->getCode());
        if (!$attributes) {
            return $proceed($optionIds, $storeId, $attributeCodes);
        }
        $requested = array_fill_keys(array_map('strval', $optionIds), true);
        $result = [];
        foreach ($attributes as $code => $attribute) {
            $withoutResults = (int)($attribute['filterableMode'] ?? 0) === self::FILTERABLE_WITHOUT_RESULTS;
            $options = [];
            foreach ((array)($attribute['options'] ?? []) as $option) {
                if ($withoutResults || isset($requested[(string)$option['id']])) {
                    $options[(string)$option['id']] = $option['label'];
                }
            }
            $listed = $options || $withoutResults
                || (in_array($code, $attributeCodes, true) && in_array($attribute['frontendInput'] ?? '', ['boolean', 'price'], true));
            if (!$listed) {
                continue;
            }
            $result[$code] = [
                'attribute_id' => (string)$attribute['id'],
                'attribute_code' => $code,
                'attribute_label' => $attribute['label'] ?? $code,
                'attribute_type' => $attribute['frontendInput'] ?? null,
                'position' => (string)($attribute['position'] ?? 0),
                'is_filterable' => (int)($attribute['filterableMode'] ?? 0),
                'options' => $options,
            ];
        }

        return $result;
    }
}
