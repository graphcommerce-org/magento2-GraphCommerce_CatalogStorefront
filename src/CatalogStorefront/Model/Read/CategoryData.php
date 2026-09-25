<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

/**
 * The data of a category model from its document: the custom attributes as the feed exports
 * them, and the fields of the entity row and the tree under the names the model reads.
 */
class CategoryData
{
    private const FIELDS = [
        'entity_id' => 'categoryId',
        'parent_id' => 'parentId',
        'name' => 'name',
        'description' => 'description',
        'meta_title' => 'metaTitle',
        'meta_keywords' => 'metaKeywords',
        'meta_description' => 'metaDescription',
        'display_mode' => 'displayMode',
        'url_key' => 'urlKey',
        'url_path' => 'urlPath',
        'level' => 'level',
        'path' => 'path',
        'children' => 'children',
        'position' => 'position',
        'default_sort_by' => 'defaultSortBy',
        'available_sort_by' => 'availableSortBy',
        'is_anchor' => 'isAnchor',
        'include_in_menu' => 'includeInMenu',
        'is_active' => 'isActive',
        'created_at' => 'createdAt',
        'updated_at' => 'updatedAt',
    ];

    /**
     * @return array<string, mixed>
     */
    public function fromDocument(array $document): array
    {
        $data = [];
        foreach ((array)($document['customAttributes'] ?? []) as $attribute) {
            if (isset($attribute['attributeCode'])) {
                $data[$attribute['attributeCode']] = $attribute['value'] ?? null;
            }
        }
        foreach (self::FIELDS as $key => $documentKey) {
            $data[$key] = $document[$documentKey] ?? null;
        }
        // The feed writes an empty url path for the tree root where the attribute is unset.
        $data['url_path'] = $data['url_path'] === '' ? null : $data['url_path'];
        $data['children_count'] = (string)count((array)($document['children'] ?? []));

        return $data;
    }
}
