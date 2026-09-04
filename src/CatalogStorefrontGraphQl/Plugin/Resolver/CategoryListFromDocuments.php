<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQl\Model\CategoryDocuments;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Serves the categories and categoryList queries from the category documents:
 * the active categories matching the id, uid, url key, url path or parent
 * filters, by position then id, paged as asked, each with its breadcrumbs
 * and its active children to the depth the query selects. A name match or
 * a page past the end keeps the core path, which reports it.
 */
class CategoryListFromDocuments
{
    private const FILTERS = [
        'ids' => 'id',
        'category_uid' => 'id',
        'url_key' => 'urlKey',
        'url_path' => 'urlPath',
        'parent_id' => 'parentId',
        'parent_category_uid' => 'parentId',
    ];

    private const DEFAULT_PAGE_SIZE = 20;

    public function __construct(
        private readonly HydrationInterface $hydration,
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly CategoryDocuments $categoryDocuments,
        private readonly Uid $uidEncoder,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        ResolverInterface $subject,
        \Closure $proceed,
        Field $field,
        ContextInterface $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $pageSize = (int)($args['pageSize'] ?? self::DEFAULT_PAGE_SIZE);
        $currentPage = (int)($args['currentPage'] ?? 1);
        $filter = $this->filter($args, $context);
        if (!$this->hydration->enabled() || $pageSize < 1 || $currentPage < 1) {
            return $proceed($field, $context, $info, $value, $args);
        }
        if ($filter === null) {
            $this->strict->fallback(self::class, 'unsupported categories filter');

            return $proceed($field, $context, $info, $value, $args);
        }

        try {
            $store = $context->getExtensionAttributes()->getStore();
            ['documents' => $documents, 'total' => $total] = $this->storage->find(
                CategoryDocuments::ENTITY,
                $store->getCode(),
                $filter + ['isActive' => 1],
                [['position', 'asc'], ['id', 'asc']],
                ($currentPage - 1) * $pageSize,
                $pageSize
            );
            $totalPages = (int)ceil($total / $pageSize);
            if ($currentPage > $totalPages && $total > 0) {
                $this->strict->fallback(self::class, 'page past the last page');
                return $proceed($field, $context, $info, $value, $args);
            }
            $documents = array_combine(array_map('intval', array_keys($documents)), $documents);
            $selection = (array)($info->getFieldSelection(20)['items'] ?? []);
            $categories = $this->categoryDocuments->hydrate($store, $documents, array_keys($selection));
            $this->categoryDocuments->breadcrumbs($store, $categories, $documents);
            $this->categoryDocuments->children($store, $categories, $documents, $this->depth($selection), $this->childFields($selection));
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);

            return $proceed($field, $context, $info, $value, $args);
        }

        return [
            'items' => array_values($categories),
            'total_count' => $total,
            'page_info' => ['total_pages' => $totalPages, 'page_size' => $pageSize, 'current_page' => $currentPage],
        ];
    }

    /**
     * @return array<string, scalar[]>|null the document filter; null for a filter the documents cannot answer
     */
    private function filter(?array $args, ContextInterface $context): ?array
    {
        $filters = (array)($args['filters'] ?? []);
        if (!$filters) {
            return ['id' => [(int)$context->getExtensionAttributes()->getStore()->getRootCategoryId()]];
        }
        $filter = [];
        foreach ($filters as $name => $condition) {
            $documentField = self::FILTERS[$name] ?? null;
            $values = $condition['in'] ?? (isset($condition['eq']) ? [$condition['eq']] : null);
            if ($documentField === null || $values === null || count($condition) !== 1) {
                return null;
            }
            if (str_ends_with($name, '_uid')) {
                $values = array_map(fn($uid) => $this->uidEncoder->decode((string)$uid), $values);
            }
            $filter[$documentField] = array_values(array_map('strval', $values));
        }

        return $filter;
    }

    /**
     * How many levels of children the query selects.
     */
    private function depth(array $selection): int
    {
        return isset($selection['children']) && is_array($selection['children']) ? 1 + $this->depth($selection['children']) : 0;
    }

    /**
     * @return string[] the fields selected on any child level
     */
    private function childFields(array $selection): array
    {
        $fields = [];
        for ($level = $selection['children'] ?? null; is_array($level); $level = $level['children'] ?? null) {
            $fields = array_merge($fields, array_keys($level));
        }

        return array_values(array_unique($fields));
    }
}
