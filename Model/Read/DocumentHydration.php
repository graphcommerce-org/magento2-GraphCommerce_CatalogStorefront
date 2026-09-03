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
                in_array("price_range", $requestedFields, true) ? $groupKey : null,
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
                $needed[] = "attributes";
            }
        }
        $excludable = array_merge(["attributes"], ...array_values($this->fieldDocumentKeys));

        return array_values(array_diff(array_unique($excludable), $needed));
    }
}
