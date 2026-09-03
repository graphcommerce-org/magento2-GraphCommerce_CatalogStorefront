<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use Magento\Catalog\Api\Data\ProductSearchResultsInterfaceFactory;
use Magento\Catalog\Model\Product;
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
 * When the query asks for price_range, the variant documents of every
 * configurable in the set are fetched in one request, limited to the fields
 * a price range needs, and attached to the parent model.
 */
class DocumentHydration
{
    public const CONFIG_SERVE_READS = 'graphcommerce/catalog_storefront/serve_reads';

    public const VARIANTS_KEY = '_gc_variants';

    private const VARIANT_FIELDS = ['productId', 'status', 'prices', 'stock', 'inStock'];

    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly ProductModelBuilder $modelBuilder,
        private readonly ProductSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CONFIG_SERVE_READS, ScopeInterface::SCOPE_STORE);
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
            $documents = [];
            foreach ($this->storage->get($store->getCode(), $ids) as $entry) {
                $documents[(int)$entry->getId()] = $entry->getData();
            }
            $models = $this->buildModels($store, $documents, $requestedFields);
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
     * @param string[] $requestedFields product fields selected by the query
     * @return array<int, Product> product id => model
     */
    public function buildModels(StoreInterface $store, array $documents, array $requestedFields): array
    {
        $variants = [];
        $variantIds = array_merge(...array_map(
            static fn(array $document) => array_values($document['variantIds'] ?? []),
            array_values($documents)
        ));
        if ($variantIds && in_array('price_range', $requestedFields, true)) {
            foreach ($this->storage->get($store->getCode(), $variantIds, self::VARIANT_FIELDS) as $entry) {
                $variants[(int)$entry->getId()] = $entry->getData();
            }
        }

        $storeId = (int)$store->getId();
        $models = [];
        foreach ($documents as $id => $document) {
            $model = $this->modelBuilder->build($document, $storeId);
            if ($model === null) {
                continue;
            }
            if ($variants && isset($document['variantIds'])) {
                $model->setData(self::VARIANTS_KEY, array_values(array_intersect_key(
                    $variants,
                    array_flip($document['variantIds'])
                )));
            }
            $models[(int)$id] = $model;
        }

        return $models;
    }
}
