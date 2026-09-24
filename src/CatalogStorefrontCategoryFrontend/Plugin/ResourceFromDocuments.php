<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Plugin;

use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\LoadedCollectionFactory;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A rendered page's categories from their documents: the category a page loads, its
 * children, its parents and its design parent. A category without a document, and every
 * category while the listing setting is off, loads from the database.
 */
class ResourceFromDocuments
{
    public function __construct(
        private readonly CategoryDocuments $documents,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoadedCollectionFactory $collectionFactory,
        private readonly CategoryFactory $categoryFactory,
    ) {
    }

    /**
     * @param mixed $object
     * @param mixed $entityId
     * @param mixed $attributes
     * @return mixed
     */
    public function aroundLoad(CategoryResource $subject, \Closure $proceed, $object, $entityId, $attributes = [])
    {
        if (!$object instanceof Category || $attributes || !$this->mode->listing((int)$object->getStoreId())) {
            return $proceed($object, $entityId, $attributes);
        }
        $storeId = (int)$object->getStoreId();
        $storeViewCode = (string)$this->storeManager->getStore($storeId)->getCode();
        $document = $this->documents->documents($storeViewCode, [(int)$entityId])[(int)$entityId] ?? null;
        if ($document === null) {
            return $proceed($object, $entityId, $attributes);
        }
        $this->documents->fill($object, $document, $storeId);
        $object->isObjectNew(false);

        return $subject;
    }

    /**
     * @param mixed $category
     * @return mixed
     */
    public function aroundGetChildrenCategories(CategoryResource $subject, \Closure $proceed, $category)
    {
        $document = $this->document($category);
        if ($document === null) {
            return $proceed($category);
        }
        $children = array_filter(
            $this->models($category, array_map('intval', (array)($document['children'] ?? []))),
            static fn (Category $child) => (bool)$child->getData('is_active')
        );
        uasort($children, static fn (Category $a, Category $b) =>
            [(int)$a->getData('position'), (int)$a->getId()] <=> [(int)$b->getData('position'), (int)$b->getId()]);

        return $this->collectionFactory->create()->withItems($children);
    }

    /**
     * @param mixed $category
     * @return mixed
     */
    public function aroundGetParentCategories(CategoryResource $subject, \Closure $proceed, $category)
    {
        if ($this->document($category) === null) {
            return $proceed($category);
        }
        $pathIds = array_map('intval', explode(',', (string)$category->getPathInStore()));

        return array_filter(
            $this->models($category, array_reverse($pathIds)),
            static fn (Category $parent) => (bool)$parent->getData('is_active')
        );
    }

    /**
     * @param mixed $category
     * @return mixed
     */
    public function aroundGetParentDesignCategory(CategoryResource $subject, \Closure $proceed, $category)
    {
        if ($this->document($category) === null) {
            return $proceed($category);
        }
        foreach ($this->models($category, array_reverse(array_map('intval', $category->getPathIds()))) as $parent) {
            if ((int)$parent->getData('level') !== 0 && !$parent->getData('custom_use_parent_settings')) {
                return $parent;
            }
        }

        return $this->categoryFactory->create();
    }

    private function document(mixed $category): ?array
    {
        $document = $category instanceof Category ? $category->getData(CategoryDocuments::DOCUMENT_KEY) : null;

        return is_array($document) ? $document : null;
    }

    /**
     * @param int[] $ids
     * @return array<int, Category>
     */
    private function models(Category $category, array $ids): array
    {
        $storeId = (int)$category->getStoreId();

        return $this->documents->models((string)$this->storeManager->getStore($storeId)->getCode(), $storeId, $ids);
    }
}
