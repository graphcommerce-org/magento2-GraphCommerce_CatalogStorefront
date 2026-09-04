<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Catalog\Model\CategoryFactory;
use Magento\CatalogGraphQl\Model\AttributesJoiner;
use Magento\CatalogGraphQl\Model\Category\Hydrator;
use Magento\CatalogGraphQl\Model\Resolver\Categories;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Serves a product's categories from the category documents. The product
 * document's category data holds what the category product index holds for
 * the store view: the assigned categories and their anchor ancestors, so only
 * the store's root category is left out, as core does. Every product of a
 * page registers its ids first and the documents are fetched in one request
 * when the first deferred value resolves, by id ascending as core's
 * collection returns them. The product count is the feed's, pre-filled.
 */
class CategoriesFromDocuments implements ResetAfterRequestInterface
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

    /** @var array<string, array<int, true>> ids to fetch per store view */
    private array $pending = [];

    /** @var array<string, array<int, array|null>> documents fetched per store view */
    private array $loaded = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly CategoryFactory $categoryFactory,
        private readonly Hydrator $hydrator,
        private readonly AttributesJoiner $attributesJoiner,
        private readonly ValueFactory $valueFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function aroundResolve(
        Categories $subject,
        \Closure $proceed,
        Field $field,
        ContextInterface $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(HydrationInterface::DOCUMENT_KEY);
        if (!is_array($document) || in_array('orders', $info->path, true)) {
            return $proceed($field, $context, $info, $value, $args);
        }

        $store = $context->getExtensionAttributes()->getStore();
        $storeViewCode = $store->getCode();
        $rootId = (int)$store->getRootCategoryId();
        $ids = [];
        foreach ((array)($document['categoryData'] ?? []) as $category) {
            $id = (int)($category['categoryId'] ?? 0);
            if ($id && $id !== $rootId) {
                $ids[$id] = $id;
                $this->pending[$storeViewCode][$id] = true;
            }
        }
        sort($ids);

        return $this->valueFactory->create(function () use ($proceed, $field, $context, $info, $value, $args, $store, $storeViewCode, $ids) {
            try {
                if (!empty($this->pending[$storeViewCode])) {
                    $fetched = $this->storage->get('category', $storeViewCode, array_keys($this->pending[$storeViewCode]));
                    foreach (array_keys($this->pending[$storeViewCode]) as $id) {
                        $this->loaded[$storeViewCode][$id] = $fetched[$id] ?? null;
                    }
                    $this->pending[$storeViewCode] = [];
                }
                $requestedFields = $this->attributesJoiner->getQueryFields($info->fieldNodes[0], $info);
                $categories = [];
                foreach ($ids as $id) {
                    $document = $this->loaded[$storeViewCode][$id] ?? null;
                    if ($document === null) {
                        continue;
                    }
                    $data = [];
                    foreach (self::FIELDS as $key => $documentKey) {
                        $data[$key] = $document[$documentKey] ?? null;
                    }
                    $data['children_count'] = (string)count((array)($document['children'] ?? []));
                    // Every requested field has a key, so the hydrator's plain data path answers all of them.
                    foreach ($requestedFields as $requestedField) {
                        $data[$requestedField] ??= null;
                    }
                    $category = $this->categoryFactory->create();
                    $category->setData($data);
                    $category->setStoreId((int)$store->getId());
                    $categories[$id] = $this->hydrator->hydrateCategory($category, true);
                    $categories[$id][PrefillerInterface::KEY] = ['product_count' => (int)($document['productCount'] ?? 0)];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('catalog-storefront categories fallback: ' . $e->getMessage());

                return $proceed($field, $context, $info, $value, $args);
            }

            return $categories;
        });
    }

    public function _resetState(): void
    {
        $this->pending = [];
        $this->loaded = [];
    }
}
