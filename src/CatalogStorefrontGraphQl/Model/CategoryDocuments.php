<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\CatalogGraphQl\Model\Category\Hydrator;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Category values for GraphQL from the category documents: each document
 * becomes a category model run through core's hydrator, with the product
 * count and the breadcrumbs pre-filled, and the active children nested to
 * the depth a query asks for, by position as core sorts them.
 */
class CategoryDocuments
{
    public const ENTITY = 'category';

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
        'image' => 'image',
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

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly CategoryFactory $categoryFactory,
        private readonly Hydrator $hydrator,
        private readonly Uid $uidEncoder,
    ) {
    }

    /**
     * @param int[] $ids
     * @return array[] the documents that exist, keyed by category id
     */
    public function documents(string $storeViewCode, array $ids): array
    {
        return $ids ? $this->storage->get(self::ENTITY, $storeViewCode, array_values(array_unique($ids))) : [];
    }

    /**
     * @param array[] $documents keyed by category id
     * @param string[] $requestedFields the category fields the query selects
     * @return array[] the category values keyed by category id
     */
    public function hydrate(StoreInterface $store, array $documents, array $requestedFields): array
    {
        $categories = [];
        foreach ($documents as $id => $document) {
            $data = [];
            foreach (self::FIELDS as $key => $documentKey) {
                $data[$key] = $document[$documentKey] ?? null;
            }
            // The feed writes an empty url path for the tree root where the attribute is unset.
            $data['url_path'] = $data['url_path'] === '' ? null : $data['url_path'];
            $data['children_count'] = (string)count((array)($document['children'] ?? []));
            // Every requested field has a key, so the hydrator's plain data path answers all of them.
            foreach ($requestedFields as $requestedField) {
                $data[$requestedField] ??= null;
            }
            $category = $this->categoryFactory->create();
            $category->setData($data);
            $category->setStoreId((int)$store->getId());
            $categories[(int)$id] = $this->hydrator->hydrateCategory($category, true);
            $categories[(int)$id][PrefillerInterface::KEY] = ['product_count' => (int)($document['productCount'] ?? 0)];
        }

        return $categories;
    }

    /**
     * Pre-fills the breadcrumbs of every category: its active ancestors below
     * the tree root, in path order, as core lists them.
     *
     * @param array[] $categories the hydrated values keyed by category id
     * @param array[] $documents the documents keyed by category id
     */
    public function breadcrumbs(StoreInterface $store, array &$categories, array $documents): void
    {
        $ancestorIds = [];
        foreach ($documents as $document) {
            $ancestorIds = array_merge($ancestorIds, array_slice(explode('/', (string)($document['path'] ?? '')), 2, -1));
        }
        $ancestors = $this->documents($store->getCode(), array_map('intval', $ancestorIds));
        foreach ($categories as $id => $category) {
            $breadcrumbs = [];
            foreach (array_slice(explode('/', (string)($documents[$id]['path'] ?? '')), 2, -1) as $ancestorId) {
                $ancestor = $ancestors[(int)$ancestorId] ?? null;
                if ($ancestor === null || empty($ancestor['isActive'])) {
                    continue;
                }
                $breadcrumbs[] = [
                    'category_id' => (int)$ancestorId,
                    'category_uid' => $this->uidEncoder->encode((string)$ancestorId),
                    'category_name' => $ancestor['name'] ?? null,
                    'category_level' => $ancestor['level'] ?? null,
                    'category_url_key' => $ancestor['urlKey'] ?? null,
                    'category_url_path' => $ancestor['urlPath'] ?? null,
                ];
            }
            $categories[$id][PrefillerInterface::KEY]['breadcrumbs'] = $breadcrumbs ?: null;
        }
    }

    /**
     * Nests the active children under every category, level by level to the
     * given depth, by position then id.
     *
     * @param array[] $categories the hydrated values keyed by category id
     * @param array[] $documents the documents keyed by category id
     * @param string[] $requestedFields the fields the query selects on the children
     */
    public function children(StoreInterface $store, array &$categories, array $documents, int $depth, array $requestedFields): void
    {
        if ($depth < 1) {
            return;
        }
        $childIds = [];
        foreach ($documents as $document) {
            $childIds = array_merge($childIds, array_map('intval', (array)($document['children'] ?? [])));
        }
        $childDocuments = array_filter(
            $this->documents($store->getCode(), $childIds),
            static fn(array $child) => !empty($child['isActive'])
        );
        $children = $this->hydrate($store, $childDocuments, $requestedFields);
        $this->children($store, $children, $childDocuments, $depth - 1, $requestedFields);
        foreach ($categories as $id => $category) {
            $own = array_intersect_key($children, array_flip(array_map('intval', (array)($documents[$id]['children'] ?? []))));
            uasort($own, static fn(array $a, array $b) => [(int)$a['position'], (int)$a['id']] <=> [(int)$b['position'], (int)$b['id']]);
            $categories[$id]['children'] = $own;
            $categories[$id]['children_count'] = count($own);
        }
    }
}
