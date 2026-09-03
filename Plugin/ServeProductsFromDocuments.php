<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin;

use GraphCommerce\CatalogStorefront\Model\Storage\DocumentStore;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterfaceFactory;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\ProductSearch;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\EntityManager\HydratorPool;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Serves the products GraphQL query from the document store.
 *
 * The search engine has already selected and ordered the product ids in
 * $searchResult. This plugin replaces only the hydration step: instead of a
 * collection load plus per-product lazy loads, each product model is rebuilt
 * from its stored document. Any miss falls back to the original code path.
 */
class ServeProductsFromDocuments
{
    public const CONFIG_SERVE_READS = 'graphcommerce/catalog_storefront/serve_reads';

    public function __construct(
        private readonly DocumentStore $documentStore,
        private readonly ProductFactory $productFactory,
        private readonly HydratorPool $hydratorPool,
        private readonly ProductSearchResultsInterfaceFactory $searchResultsFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function aroundGetList(
        ProductSearch $subject,
        \Closure $proceed,
        SearchCriteriaInterface $searchCriteria,
        SearchResultInterface $searchResult,
        array $attributes = [],
        ?ContextInterface $context = null
    ): SearchResultsInterface {
        if (!$this->scopeConfig->isSetFlag(self::CONFIG_SERVE_READS, ScopeInterface::SCOPE_STORE)) {
            return $proceed($searchCriteria, $searchResult, $attributes, $context);
        }

        try {
            $storeId = (int)($context?->getExtensionAttributes()->getStore()?->getId()
                ?? $this->storeManager->getStore()->getId());

            $ids = [];
            foreach ($searchResult->getItems() as $item) {
                $ids[] = (int)$item->getId();
            }
            if (!$ids || !$this->documentStore->hasIndex($storeId)) {
                return $proceed($searchCriteria, $searchResult, $attributes, $context);
            }

            $documents = $this->documentStore->get($storeId, $ids);
            if (count($documents) < count($ids)) {
                return $proceed($searchCriteria, $searchResult, $attributes, $context);
            }

            $hydrator = $this->hydratorPool->getHydrator(ProductInterface::class);
            $items = [];
            foreach ($ids as $id) {
                $model = $this->productFactory->create();
                $hydrator->hydrate($model, $documents[$id]);
                $model->setStoreId($storeId);
                $items[$id] = $model;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('catalog-storefront read fallback: ' . $e->getMessage());

            return $proceed($searchCriteria, $searchResult, $attributes, $context);
        }

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($items);
        $searchResults->setTotalCount($searchResult->getTotalCount());

        return $searchResults;
    }
}
