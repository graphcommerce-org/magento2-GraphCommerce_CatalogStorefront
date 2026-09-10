<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\AttributeOptionProvider;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Serves the facet attribute and option labels from the attribute documents
 * on the document path, shaped as core's provider returns them: an attribute
 * is listed when one of the requested option ids is its own, when it is
 * filterable without results (then with every option), or when it is a
 * requested boolean or price attribute. The documents come from the primed
 * facet read, or from one query. Falls back to core while the store view has
 * no attribute documents.
 */
class AttributeOptionsFromDocuments
{
    public function __construct(
        private readonly FacetDocuments $facets,
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly StoreManagerInterface $storeManager,
        private readonly Mode $mode,
        private readonly Strict $strict,
    ) {
    }

    public function aroundGetOptions(
        AttributeOptionProvider $subject,
        \Closure $proceed,
        array $optionIds,
        ?int $storeId,
        array $attributeCodes = []
    ): array {
        if (!$this->mode->documents()) {
            return $proceed($optionIds, $storeId, $attributeCodes);
        }
        if (!$optionIds) {
            return [];
        }
        try {
            $storeCode = $this->storeManager->getStore($storeId)->getCode();
            $attributes = $this->facets->attributes($storeCode, $optionIds, $attributeCodes)
                ?? $this->storage->any('attribute', $storeCode, FacetDocuments::alternatives($optionIds, $attributeCodes));
            if (!$attributes && $this->storage->count('attribute', $storeCode) === 0) {
                $this->strict->fallback(self::class, 'no attribute documents for the store view');
                return $proceed($optionIds, $storeId, $attributeCodes);
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
            return $proceed($optionIds, $storeId, $attributeCodes);
        }
        $requested = array_fill_keys(array_map('strval', $optionIds), true);
        $result = [];
        foreach ($attributes as $code => $attribute) {
            $withoutResults = (int)($attribute['filterableMode'] ?? 0) === FacetDocuments::FILTERABLE_WITHOUT_RESULTS;
            $documentOptions = [];
            foreach ((array)($attribute['options'] ?? []) as $option) {
                if ($withoutResults || isset($requested[(string)$option['id']])) {
                    $documentOptions[] = $option;
                }
            }
            $hasCompleteFacetOrder = $documentOptions && count(array_filter(
                $documentOptions,
                static fn(array $option): bool => isset($option['facetSortOrder'])
            )) === count($documentOptions);
            if ($hasCompleteFacetOrder) {
                usort($documentOptions, static function (array $left, array $right): int {
                    return ((int)$left['facetSortOrder'] <=> (int)$right['facetSortOrder'])
                        ?: ((int)$left['id'] <=> (int)$right['id']);
                });
            }
            $options = [];
            foreach ($documentOptions as $option) {
                $options[(string)$option['id']] = $option['label'];
            }
            $listed = $options || $withoutResults
                || (in_array($code, $attributeCodes, true) && in_array($attribute['frontendInput'] ?? '', ['boolean', 'price'], true));
            if (!$listed) {
                continue;
            }
            $result[$code] = [
                'attribute_id' => (string)$attribute['attributeId'],
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
