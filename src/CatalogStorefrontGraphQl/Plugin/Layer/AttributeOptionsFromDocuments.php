<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\AttributeOptionProvider;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Serves the facet attribute and option labels from the attribute documents
 * on the document path, shaped as core's provider returns them. One query
 * fetches the attributes that own a requested option id, the attributes
 * filterable without results (listed with every option), and the requested
 * boolean and price attributes. Falls back to core while the store view has
 * no attribute documents.
 */
class AttributeOptionsFromDocuments
{
    private const FILTERABLE_WITHOUT_RESULTS = 2;

    public function __construct(
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
        $alternatives = [
            ['options.id' => $optionIds],
            ['filterableMode' => self::FILTERABLE_WITHOUT_RESULTS],
        ];
        if ($attributeCodes) {
            $alternatives[] = ['id' => $attributeCodes, 'frontendInput' => ['boolean', 'price']];
        }
        try {
            $storeCode = $this->storeManager->getStore($storeId)->getCode();
            $attributes = $this->storage->any('attribute', $storeCode, $alternatives);
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
            $withoutResults = (int)($attribute['filterableMode'] ?? 0) === self::FILTERABLE_WITHOUT_RESULTS;
            $options = [];
            foreach ((array)($attribute['options'] ?? []) as $option) {
                if ($withoutResults || isset($requested[(string)$option['id']])) {
                    $options[(string)$option['id']] = $option['label'];
                }
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
