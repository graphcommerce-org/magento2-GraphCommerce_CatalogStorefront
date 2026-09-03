<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use Magento\Catalog\Api\Data\ProductSearchResultsInterfaceFactory;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds product models from feed documents for the catalog GraphQL read
 * path: the two data provider plugins and the linked products resolver.
 *
 * A listing page is one storage request: the documents with the heavy fields
 * the query does not select left out, and, when price_range is selected, the
 * configurable price ranges for the request's customer group.
 */
class DocumentHydration
{
    public const CONFIG_SERVE_READS = 'graphcommerce/catalog_storefront/serve_reads';

    public const PRICE_RANGE_KEY = '_gc_price_range';

    /**
     * Document keys a listing can leave out, by the GraphQL fields that need
     * them. Any other selected field is served from the base keys, except
     * custom attribute fields, which need the attributes key.
     */
    private const HEAVY_KEYS = [
        'description' => ['description'],
        'short_description' => ['shortDescription'],
        'media_gallery' => ['media_gallery', 'images', 'videos'],
        'media_gallery_entries' => ['media_gallery', 'images', 'videos'],
        'url_rewrites' => ['urlRewrites'],
        'related_products' => ['links'],
        'upsell_products' => ['links'],
        'crosssell_products' => ['links'],
        'categories' => ['categoryData'],
        'configurable_options' => ['optionsV2'],
        'options' => ['optionsV2', 'shopperInputOptions'],
        'downloadable_product_links' => ['optionsV2'],
        'downloadable_product_samples' => ['samples'],
        'links_purchased_separately' => ['optionsV2'],
    ];

    private const BASE_FIELDS = [
        '__typename', 'uid', 'id', 'sku', 'name', 'url_key', 'url_suffix', 'type_id', 'created_at', 'updated_at',
        'image', 'small_image', 'thumbnail', 'price_range', 'stock_status', 'new_from_date', 'new_to_date',
        'rating_summary', 'review_count', 'max_sale_qty', 'canonical_url', 'only_x_left_in_stock',
    ];

    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly ProductModelBuilder $modelBuilder,
        private readonly ProductSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly ProductPrice $productPrice,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_SERVE_READS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * The price group key of the request's customer.
     */
    public function groupKey(?ContextInterface $context): string
    {
        $groupId = $context?->getExtensionAttributes()->getCustomerGroupId()
            ?? $this->customerSession->getCustomerGroupId();

        return $this->productPrice->groupKey((int)$groupId);
    }

    /**
     * Rebuilds a result from a resolved id list, or returns null to fall back.
     *
     * @param int[] $ids in the order the caller resolved them
     * @param string[] $requestedFields product fields selected by the query
     */
    public function rebuildFromIds(
        array $ids,
        int $totalCount,
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria,
        ?ContextInterface $context,
        array $requestedFields = []
    ): ?SearchResultsInterface {
        try {
            $store = $context?->getExtensionAttributes()->getStore() ?? $this->storeManager->getStore();
            if (!$ids) {
                return null;
            }
            [$documents, $ranges] = $this->storage->listing(
                $store->getCode(),
                $ids,
                $this->sourceExcludes($requestedFields),
                in_array('price_range', $requestedFields, true) ? $this->groupKey($context) : null
            );
            $models = $this->buildModels($store, $documents, $ranges);
            $items = [];
            foreach ($ids as $id) {
                if (!isset($models[$id])) {
                    return null;
                }
                $items[$id] = $models[$id];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('catalog-storefront read fallback: ' . $e->getMessage());

            return null;
        }

        $result = $this->searchResultsFactory->create();
        $result->setSearchCriteria($searchCriteria);
        $result->setItems($items);
        $result->setTotalCount($totalCount);

        return $result;
    }

    /**
     * Documents without the products feed slice are left out.
     *
     * @param array<int, array> $documents product id => document
     * @param array<int, array> $ranges configurable price ranges by parent id
     * @return array<int, Product> product id => model
     */
    public function buildModels(StoreInterface $store, array $documents, array $ranges): array
    {
        $storeId = (int)$store->getId();
        $models = [];
        foreach ($documents as $id => $document) {
            $model = $this->modelBuilder->build($document, $storeId);
            if ($model === null) {
                continue;
            }
            if (isset($ranges[(int)$id])) {
                $model->setData(self::PRICE_RANGE_KEY, $ranges[(int)$id]);
            }
            $models[(int)$id] = $model;
        }

        return $models;
    }

    /**
     * @param string[] $requestedFields
     * @return string[] document keys to leave out of the fetch
     */
    private function sourceExcludes(array $requestedFields): array
    {
        $needed = [];
        foreach ($requestedFields as $field) {
            if (isset(self::HEAVY_KEYS[$field])) {
                $needed = array_merge($needed, self::HEAVY_KEYS[$field]);
            } elseif (!in_array($field, self::BASE_FIELDS, true)) {
                $needed[] = 'attributes';
            }
        }
        $excludable = array_merge(['attributes'], ...array_values(self::HEAVY_KEYS));

        return array_values(array_diff(array_unique($excludable), $needed));
    }
}
