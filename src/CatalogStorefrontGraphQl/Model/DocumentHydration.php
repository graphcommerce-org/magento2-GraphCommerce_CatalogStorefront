<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model;

use GraphCommerce\CatalogStorefront\Model\Mode;
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
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * The product models of a GraphQL query: built from documents and run
 * through the prefillers (di.xml `prefillers`, in order) for the fields the
 * query selects.
 */
class DocumentHydration implements HydrationInterface
{
    /**
     * @param PrefillerInterface[] $prefillers
     * @param string[] $priceFields product fields whose value needs the composite price data
     */
    public function __construct(
        private readonly ProductDocumentsInterface $products,
        private readonly ProductDocumentStorageInterface $storage,
        private readonly Mode $mode,
        private readonly ProductSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly ProductPrice $productPrice,
        private readonly Strict $strict,
        private readonly array $prefillers = [],
        private readonly array $priceFields = [],
    ) {
    }

    public function enabled(): bool
    {
        return $this->mode->documents();
    }

    public function groupKey(?ContextInterface $context): string
    {
        $groupId =
            $context?->getExtensionAttributes()->getCustomerGroupId() ?? $this->customerSession->getCustomerGroupId();

        return $this->productPrice->groupKey((int)$groupId);
    }

    /**
     * A listing page as one multi-search: the documents by id, and the
     * composite price data when a price field is selected.
     */
    public function rebuildFromIds(
        array $ids,
        int $totalCount,
        SearchCriteriaInterface $searchCriteria,
        ?ContextInterface $context,
        array $requestedFields = [],
    ): SearchResultsInterface {
        if (!$ids) {
            $result = $this->searchResultsFactory->create();
            $result->setSearchCriteria($searchCriteria);
            $result->setItems([]);
            $result->setTotalCount($totalCount);
            return $result;
        }
        try {
            $store = $context?->getExtensionAttributes()->getStore() ?? $this->storeManager->getStore();
            $groupKey = $this->groupKey($context);
            $withPrices = array_intersect($this->priceFields, $requestedFields) !== [];
            [$documents, $priceData] = $this->storage->listing(
                $store->getCode(),
                $ids,
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
                    $this->strict->fallback(self::class, 'no document for product ' . $id);
                }
                $items[$id] = $models[$id];
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
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
}
