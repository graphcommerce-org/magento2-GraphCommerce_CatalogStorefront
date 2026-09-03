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
    public const CONFIG_SERVE_READS = "graphcommerce/catalog_storefront/serve_reads";

    public const PRICE_RANGE_KEY = "_gc_price_range";

    /**
     * @param array<string, string[]> $fieldDocumentKeys document keys a GraphQL field needs beyond the
     *        base keys; a listing leaves out every such key its query does not select (di.xml)
     * @param string[] $baseFields GraphQL fields served from the base keys; any other selected field
     *        that is not in $fieldDocumentKeys is a custom attribute and keeps the attributes key (di.xml)
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
        private readonly array $fieldDocumentKeys = [],
        private readonly array $baseFields = [],
    ) {}

    public function enabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_SERVE_READS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * The price group key of the request's customer.
     */
    public function groupKey(?ContextInterface $context): string
    {
        $groupId =
            $context?->getExtensionAttributes()->getCustomerGroupId() ?? $this->customerSession->getCustomerGroupId();

        return $this->productPrice->groupKey((int) $groupId);
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
        array $requestedFields = [],
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
                in_array("price_range", $requestedFields, true) ? $this->groupKey($context) : null,
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
     * Documents without the products feed slice are left out.
     *
     * @param array<int, array> $documents product id => document
     * @param array<int, array> $ranges configurable price ranges by parent id
     * @return array<int, Product> product id => model
     */
    public function buildModels(StoreInterface $store, array $documents, array $ranges): array
    {
        $storeId = (int) $store->getId();
        $models = [];
        foreach ($documents as $id => $document) {
            $model = $this->modelBuilder->build($document, $storeId);
            if ($model === null) {
                continue;
            }
            if (isset($ranges[(int) $id])) {
                $model->setData(self::PRICE_RANGE_KEY, $ranges[(int) $id]);
            }
            $models[(int) $id] = $model;
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
