<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Layer;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Catalog\Model\Config\LayerCategoryConfig;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\Builder\Aggregations\Category\IncludeDirectChildrenOnly;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\Builder\Category;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\Formatter\LayerFormatter;
use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\AggregationValueInterface;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Search\Response\Aggregation;
use Magento\Framework\Search\Response\AggregationFactory;
use Magento\Framework\Search\Response\BucketFactory;
use Magento\Store\Model\StoreManagerInterface;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Serves the category facet from the category documents: the store's tree
 * membership (path under the store root) and the store view's names replace
 * core's category collection and attribute queries, and the direct-children
 * filter of a category-filtered query reads the requested categories'
 * children and their activity from the documents instead of loading them.
 * A missing document falls back to core.
 */
class CategoryFacetFromDocuments implements ResetAfterRequestInterface
{
    private const CATEGORY_BUCKET = 'category_bucket';

    private array $filter = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly StoreManagerInterface $storeManager,
        private readonly LayerFormatter $layerFormatter,
        private readonly Uid $uidEncoder,
        private readonly LayerCategoryConfig $layerCategoryConfig,
        private readonly IncludeDirectChildrenOnly $includeDirectChildrenOnly,
        private readonly AggregationFactory $aggregationFactory,
        private readonly BucketFactory $bucketFactory,
        private readonly Strict $strict,
    ) {
    }

    public function beforeSetFilter(IncludeDirectChildrenOnly $subject, array $filter): void
    {
        $this->filter = $filter;
    }

    public function aroundFilter(
        IncludeDirectChildrenOnly $subject,
        \Closure $proceed,
        AggregationInterface $aggregation,
        ?int $storeId
    ): Aggregation {
        $requested = $this->filter['category'] ?? null;
        $buckets = $aggregation->getBuckets();
        $bucket = $buckets[self::CATEGORY_BUCKET] ?? null;
        if ($requested === null
            || !$this->layerCategoryConfig->isCategoryFilterVisibleInLayerNavigation()
            || $bucket === null
            || !$bucket->getValues()
        ) {
            return $proceed($aggregation, $storeId);
        }
        try {
            $storeCode = $this->storeManager->getStore($storeId)->getCode();
            $requested = array_map('intval', is_array($requested) ? $requested : [$requested]);
            $parents = $this->storage->get('category', $storeCode, $requested, ['children']);
            if (count($parents) !== count($requested)) {
                $this->strict->fallback(self::class, 'category documents missing for the filtered categories');
                return $proceed($aggregation, $storeId);
            }
            $childIds = array_map('intval', array_merge([], ...array_map(
                static fn(array $parent) => (array)($parent['children'] ?? []),
                array_values($parents)
            )));
            $active = array_filter(
                $this->storage->get('category', $storeCode, $childIds, ['isActive']),
                static fn(array $child) => !empty($child['isActive'])
            );
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);

            return $proceed($aggregation, $storeId);
        }
        $values = array_values(array_filter(
            $bucket->getValues(),
            static fn(AggregationValueInterface $value) => isset($active[(int)$value->getValue()])
        ));
        $buckets[self::CATEGORY_BUCKET] = $this->bucketFactory->create(['name' => self::CATEGORY_BUCKET, 'values' => $values]);

        return $this->aggregationFactory->create(['buckets' => $buckets]);
    }

    public function aroundBuild(Category $subject, \Closure $proceed, AggregationInterface $aggregation, ?int $storeId): array
    {
        try {
            $store = $this->storeManager->getStore($storeId);
            $rootId = (int)$store->getRootCategoryId();
            $filtered = $this->includeDirectChildrenOnly->filter($aggregation, $storeId);
            $bucket = $filtered->getBucket(self::CATEGORY_BUCKET);
            if ($bucket === null || !$bucket->getValues()) {
                return [];
            }
            $ids = array_map(static fn(AggregationValueInterface $value) => (int)$value->getValue(), $bucket->getValues());
            $documents = $this->storage->get('category', $store->getCode(), $ids, ['name', 'path']);
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);

            return $proceed($aggregation, $storeId);
        }
        $labels = [];
        foreach ($documents as $id => $document) {
            if ($id !== $rootId && in_array((string)$rootId, explode('/', (string)($document['path'] ?? '')), true)) {
                $labels[$id] = $document['name'] ?? (string)$id;
            }
        }
        if (!$labels) {
            return [];
        }
        $result = $this->layerFormatter->buildLayer('Category', count($labels), 'category_uid');
        foreach ($bucket->getValues() as $value) {
            $id = (int)$value->getValue();
            if (isset($labels[$id])) {
                $result['options'][] = $this->layerFormatter->buildItem(
                    $labels[$id],
                    $this->uidEncoder->encode((string)$id),
                    $value->getMetrics()['count']
                );
            }
        }

        return [$result];
    }

    public function _resetState(): void
    {
        $this->filter = [];
    }
}
