<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The facet's attribute and category documents, fetched in one request before
 * core's layer builders run: the attributes that own the aggregated option ids
 * (plus the ones filterable without results and the requested boolean and
 * price attributes), and the aggregated categories' names and paths. A builder
 * reads them here when the prime covered its request, else it fetches its own.
 */
class FacetDocuments implements ResetAfterRequestInterface
{
    public const FILTERABLE_WITHOUT_RESULTS = 2;

    /** @var array<string, array{options: array<string, true>, codes: array<string, true>, attributes: array, categoryIds: array<int, true>, categories: array}> per store view */
    private array $primed = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    /**
     * The alternatives that select the attributes a facet lists for the option ids and codes.
     */
    public static function alternatives(array $optionIds, array $attributeCodes): array
    {
        $alternatives = [
            ['options.id' => $optionIds],
            ['filterableMode' => self::FILTERABLE_WITHOUT_RESULTS],
        ];
        if ($attributeCodes) {
            $alternatives[] = ['id' => $attributeCodes, 'frontendInput' => ['boolean', 'price']];
        }

        return $alternatives;
    }

    public function prime(string $storeViewCode, array $optionIds, array $attributeCodes, array $categoryIds): void
    {
        [$attributes, $categories] = $this->storage->batch($storeViewCode, [
            ['entity' => 'attribute', 'any' => self::alternatives($optionIds, $attributeCodes)],
            ['entity' => 'category', 'ids' => $categoryIds, 'fields' => ['name', 'path']],
        ]);
        $this->primed[$storeViewCode] = [
            'options' => array_fill_keys(array_map('strval', $optionIds), true),
            'codes' => array_fill_keys($attributeCodes, true),
            'attributes' => $attributes,
            'categoryIds' => array_fill_keys(array_map('intval', $categoryIds), true),
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, array>|null the attribute documents by code; null when the prime did not cover the request
     */
    public function attributes(string $storeViewCode, array $optionIds, array $attributeCodes): ?array
    {
        $primed = $this->primed[$storeViewCode] ?? null;
        if ($primed === null
            || array_diff_key(array_fill_keys(array_map('strval', $optionIds), true), $primed['options'])
            || array_diff_key(array_fill_keys($attributeCodes, true), $primed['codes'])
        ) {
            return null;
        }

        return $primed['attributes'];
    }

    /**
     * @return array<int|string, array>|null the categories' name and path by id; null when the prime did not cover the ids
     */
    public function categories(string $storeViewCode, array $ids): ?array
    {
        $primed = $this->primed[$storeViewCode] ?? null;
        if ($primed === null || array_diff_key(array_fill_keys(array_map('intval', $ids), true), $primed['categoryIds'])) {
            return null;
        }

        return array_intersect_key($primed['categories'], array_fill_keys(array_map('strval', $ids), true));
    }

    public function _resetState(): void
    {
        $this->primed = [];
    }
}
