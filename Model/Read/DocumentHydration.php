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

class DocumentHydration
{
    public const CONFIG_SERVE_READS = "graphcommerce/catalog_storefront/serve_reads";

    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly ProductModelBuilder $modelBuilder,
        private readonly Prefill $prefill,
        private readonly ProductSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly ProductPrice $productPrice,
        private readonly LoggerInterface $logger,
        private readonly array $fieldDocumentKeys = [],
        private readonly array $baseFields = [],
    ) {}

    public function enabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_SERVE_READS, ScopeInterface::SCOPE_STORE);
    }

    public function groupKey(?ContextInterface $context): string
    {
        $groupId =
            $context?->getExtensionAttributes()->getCustomerGroupId() ?? $this->customerSession->getCustomerGroupId();

        return $this->productPrice->groupKey((int) $groupId);
    }

    public function rebuildFromIds(
        array $ids,
        int $totalCount,
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria,
        ?ContextInterface $context,
        array $requestedFields = [],
    ): ?SearchResultsInterface {
        try {
            $store = $context?->getExtensionAttributes()->getStore() ?? $this->storeManager->getStore();
            if (!$ids) {
                return null;
            }
            $groupKey = $this->groupKey($context);
            [$documents, $priceData] = $this->storage->listing(
                $store->getCode(),
                $ids,
                $this->sourceExcludes($requestedFields),
                array_intersect(Prefill::PRICE_FIELDS, $requestedFields) ? $groupKey : null,
            );
            $models = $this->buildModels($store, $documents, $priceData, $groupKey, $requestedFields);
            $items = [];
            foreach ($ids as $id) {
                if (!isset($models[$id])) {
                    return null;
                }
                $items[$id] = $models[$id];
            }
        } catch (\Throwable $e) {
            $this->logger->warning("catalog-storefront read fallback: " . $e->getMessage());

            return null;
        }

        $result = $this->searchResultsFactory->create();
        $result->setSearchCriteria($searchCriteria);
        $result->setItems($items);
        $result->setTotalCount($totalCount);

        return $result;
    }

    /**
     * @param int[] $ids
     * @return array[] the documents that exist, keyed by product id
     */
    public function documents(string $storeViewCode, array $ids): array
    {
        $documents = [];
        if ($ids) {
            foreach ($this->storage->get($storeViewCode, array_values(array_unique($ids))) as $entry) {
                $documents[(int)$entry->getId()] = $entry->getData();
            }
        }

        return $documents;
    }

    /**
     * Models of documents fetched outside a listing, with the composite price
     * data fetched when the fields ask for a price range.
     *
     * @param array[] $documents keyed by product id
     * @param string[] $requestedFields product fields the query selects; empty selects all
     * @return Product[] keyed by product id
     */
    public function models(StoreInterface $store, ?ContextInterface $context, array $documents, array $requestedFields): array
    {
        $groupKey = $this->groupKey($context);
        $priceData = [];
        $compositeIds = array_keys(array_filter(
            $documents,
            static fn(array $document) => in_array($document['type'] ?? '', ['configurable', 'grouped', 'bundle', 'bundle_fixed'], true)
        ));
        if ($compositeIds && array_intersect(Prefill::PRICE_FIELDS, $requestedFields)) {
            $priceData = $this->storage->priceData($store->getCode(), $compositeIds, $groupKey);
        }

        return $this->buildModels($store, $documents, $priceData, $groupKey, $requestedFields);
    }

    /**
     * @param array[] $documents keyed by product id
     * @param array $priceData composite price data as ProductDocumentStorage::priceData() returns it, or empty
     * @param string[] $requestedFields product fields the query selects; empty selects all
     * @return Product[] keyed by product id
     */
    public function buildModels(
        StoreInterface $store,
        array $documents,
        array $priceData,
        string $groupKey,
        array $requestedFields
    ): array {
        $storeId = (int) $store->getId();
        $models = [];
        foreach ($documents as $id => $document) {
            $model = $this->modelBuilder->build($document, $storeId);
            if ($model !== null) {
                $models[(int) $id] = $model;
            }
        }
        $this->prefill->fill($models, $documents, $store, $groupKey, $priceData, $requestedFields);

        return $models;
    }

    private function sourceExcludes(array $requestedFields): array
    {
        $needed = [];
        foreach ($requestedFields as $field) {
            if (isset($this->fieldDocumentKeys[$field])) {
                $needed = array_merge($needed, $this->fieldDocumentKeys[$field]);
            } elseif (!in_array($field, $this->baseFields, true)) {
                $needed[] = "customAttributes";
            }
        }
        // The labelled attributes slice serves nothing on read: the model takes the raw values.
        $excludable = array_merge(["attributes", "customAttributes"], ...array_values($this->fieldDocumentKeys));

        return array_values(array_diff(array_unique($excludable), $needed));
    }
}
