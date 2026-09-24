<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Read\CategoryData;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The category documents a rendered page reads, once per request, and the category models
 * built from them. A built model carries its document under DOCUMENT_KEY and its request
 * path, so getUrl() asks no rewrite.
 */
class CategoryDocuments implements ResetAfterRequestInterface
{
    public const ENTITY = 'category';
    public const DOCUMENT_KEY = '_gc_document';

    /** @var array<string, array<int, array|null>> by store view code and id; null for an id without a document */
    private array $documents = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly CategoryData $categoryData,
        private readonly CategoryFactory $categoryFactory,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * @param int[] $ids
     * @return array<int, array> the documents found, by id, in the order of the ids
     */
    public function documents(string $storeViewCode, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $missing = array_values(array_filter($ids, fn (int $id) => !array_key_exists($id, $this->documents[$storeViewCode] ?? [])));
        if ($missing !== []) {
            $read = $this->storage->get(self::ENTITY, $storeViewCode, $missing);
            foreach ($missing as $id) {
                $this->documents[$storeViewCode][$id] = $read[$id] ?? null;
            }
        }

        $found = [];
        foreach ($ids as $id) {
            if ($this->documents[$storeViewCode][$id] !== null) {
                $found[$id] = $this->documents[$storeViewCode][$id];
            }
        }

        return $found;
    }

    public function fill(Category $category, array $document, int $storeId): Category
    {
        $category->addData($this->categoryData->fromDocument($document));
        // The model builds the image URL itself; the document's image field is that URL.
        foreach ((array)($document['customAttributes'] ?? []) as $attribute) {
            if (($attribute['attributeCode'] ?? null) === 'image') {
                $category->setData('image', $attribute['value'] ?? null);
            }
        }
        $category->setData(self::DOCUMENT_KEY, $document);
        $category->setStoreId($storeId);
        $urlPath = (string)$category->getData('url_path');
        if ($urlPath !== '') {
            $suffix = (string)$this->scopeConfig->getValue(
                CategoryUrlPathGenerator::XML_PATH_CATEGORY_URL_SUFFIX,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $category->setData('request_path', $urlPath . $suffix);
        }

        return $category;
    }

    /**
     * @param int[] $ids
     * @return array<int, Category> the categories with a document, by id, in the order of the ids
     */
    public function models(string $storeViewCode, int $storeId, array $ids): array
    {
        $models = [];
        foreach ($this->documents($storeViewCode, $ids) as $id => $document) {
            $models[$id] = $this->fill($this->categoryFactory->create(), $document, $storeId);
        }

        return $models;
    }

    /**
     * A document read elsewhere, so the page's load of that category reads nothing.
     */
    public function add(string $storeViewCode, array $document): void
    {
        $this->documents[$storeViewCode][(int)($document['id'] ?? $document['categoryId'] ?? 0)] = $document;
    }

    public function _resetState(): void
    {
        $this->documents = [];
    }
}
