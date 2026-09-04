<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;
use Magento\Catalog\Api\Data\ProductSearchResultsInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The product models of a GraphQL query: built from documents and run
 * through the prefillers (di.xml `prefillers`, in order) for the fields the
 * query selects.
 */
class DocumentHydration implements HydrationInterface
{
    /**
     * @param PrefillerInterface[] $prefillers
     * @param string[][] $fieldDocumentKeys document keys a listing fetch leaves out unless the query selects the field
     * @param string[] $baseFields product fields the model answers without the custom attributes slice
     * @param string[] $priceFields product fields whose value needs the composite price data
     */
    public function __construct(
        private readonly ProductDocumentsInterface $products,
        private readonly ProductDocumentStorageInterface $storage,
        private readonly Config $config,
        private readonly ProductSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly ProductPrice $productPrice,
        private readonly LoggerInterface $logger,
        private readonly array $prefillers = [],
        private readonly array $fieldDocumentKeys = [],
        private readonly array $baseFields = [],
        private readonly array $priceFields = [],
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->serveReads();
    }

    public function groupKey(?ContextInterface $context): string
    {
        $groupId =
            $context?->getExtensionAttributes()->getCustomerGroupId() ?? $this->customerSession->getCustomerGroupId();

        return $this->productPrice->groupKey((int)$groupId);
    }

    /**
     * A listing page as one multi-search: the documents by id with the keys
     * the query does not need left out, and the composite price data when a
     * price field is selected.
     */
    public function rebuildFromIds(
        array $ids,
        int $totalCount,
        SearchCriteriaInterface $searchCriteria,
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
            $models = $this->products->build($store, $documents);
            $this->run($models, $documents, new PrefillRequest(
                $store,
                $groupKey,
                $requestedFields,
                $withPrices ? $priceData : $this->products->priceDataLoader($store, $groupKey, $documents)
            ));
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

    public function documents(string $storeViewCode, array $ids): array
    {
        return $this->products->documents($storeViewCode, $ids);
    }

    public function build(StoreInterface $store, array $documents): array
    {
        return $this->products->build($store, $documents);
    }

    public function priceDataLoader(StoreInterface $store, string $groupKey, array $documents): \Closure
    {
        return $this->products->priceDataLoader($store, $groupKey, $documents);
    }

    public function models(StoreInterface $store, ?ContextInterface $context, array $documents, array $requestedFields): array
    {
        $models = $this->products->build($store, $documents);
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
            $this->products->priceDataLoader($store, $groupKey, $documents)
        ));
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

    private function sourceExcludes(array $requestedFields): array
    {
        $needed = [];
        foreach ($requestedFields as $field) {
            if (isset($this->fieldDocumentKeys[$field])) {
                $needed = array_merge($needed, $this->fieldDocumentKeys[$field]);
            } elseif (!in_array($field, $this->baseFields, true)) {
                $needed[] = 'customAttributes';
            }
        }
        // The labelled attributes slice serves nothing on read: the model takes the raw values.
        $excludable = array_merge(['attributes', 'customAttributes'], ...array_values($this->fieldDocumentKeys));

        return array_values(array_diff(array_unique($excludable), $needed));
    }
}
