<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use GraphCommerce\CatalogStorefrontApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontApi\Read\PrefillRequest;
use Magento\Catalog\Api\Data\ProductSearchResultsInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class DocumentHydration implements HydrationInterface
{
    public const CONFIG_SERVE_READS = "graphcommerce/catalog_storefront/serve_reads";

    private const COMPOSITE_TYPES = ['configurable', 'grouped', 'bundle', 'bundle_fixed'];

    /**
     * @param PrefillerInterface[] $prefillers
     * @param string[][] $fieldDocumentKeys document keys a listing fetch leaves out unless the query selects the field
     * @param string[] $baseFields product fields the model answers without the custom attributes slice
     * @param string[] $priceFields product fields whose value needs the composite price data
     */
    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly ProductModelBuilder $modelBuilder,
        private readonly ProductSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly ProductPrice $productPrice,
        private readonly LoggerInterface $logger,
        private readonly array $prefillers = [],
        private readonly array $fieldDocumentKeys = [],
        private readonly array $baseFields = [],
        private readonly array $priceFields = [],
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

    /**
     * A listing page as one multi-search: the documents by id with the keys
     * the query does not need left out, and the composite price data when a
     * price field is selected.
     */
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
            $withPrices = array_intersect($this->priceFields, $requestedFields) !== [];
            [$documents, $priceData] = $this->storage->listing(
                $store->getCode(),
                $ids,
                $this->sourceExcludes($requestedFields),
                $withPrices ? $groupKey : null,
            );
            $models = $this->build($store, $documents);
            $this->run($models, $documents, new PrefillRequest(
                $store,
                $groupKey,
                $requestedFields,
                $withPrices ? $priceData : $this->priceDataLoader($store, $documents, $groupKey)
            ));
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

    public function models(StoreInterface $store, ?ContextInterface $context, array $documents, array $requestedFields): array
    {
        $models = $this->build($store, $documents);
        $this->prefill($store, $context, $models, $documents, $requestedFields);

        return $models;
    }

    public function prefill(StoreInterface $store, ?ContextInterface $context, array $models, array $documents, array $requestedFields): void
    {
        $groupKey = $this->groupKey($context);
        $this->run($models, $documents, new PrefillRequest(
            $store,
            $groupKey,
            $requestedFields,
            $this->priceDataLoader($store, $documents, $groupKey)
        ));
    }

    private function build(StoreInterface $store, array $documents): array
    {
        $storeId = (int) $store->getId();
        $models = [];
        foreach ($documents as $id => $document) {
            $model = $this->modelBuilder->build($document, $storeId);
            if ($model !== null) {
                $models[(int) $id] = $model;
            }
        }

        return $models;
    }

    private function run(array $models, array $documents, PrefillRequest $request): void
    {
        foreach ($this->prefillers as $prefiller) {
            foreach ($prefiller->fill($models, $documents, $request) as $id => $filled) {
                $models[$id]->setData(
                    PrefillerInterface::KEY,
                    $filled + (array)$models[$id]->getData(PrefillerInterface::KEY)
                );
            }
        }
    }

    /**
     * @return \Closure(): array the composite price data of the composite products among the documents
     */
    private function priceDataLoader(StoreInterface $store, array $documents, string $groupKey): \Closure
    {
        return function () use ($store, $documents, $groupKey): array {
            $compositeIds = array_keys(array_filter(
                $documents,
                static fn(array $document) => in_array($document['type'] ?? '', self::COMPOSITE_TYPES, true)
            ));

            return $compositeIds ? $this->storage->priceData($store->getCode(), $compositeIds, $groupKey) : [];
        };
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
