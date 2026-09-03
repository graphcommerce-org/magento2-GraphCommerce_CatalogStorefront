<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use Magento\Catalog\Api\Data\ProductSearchResultsInterfaceFactory;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Rebuilds a product search result from feed documents, preserving the id order
 * the stock provider already resolved. Shared by the plugins on both catalog
 * GraphQL data providers.
 */
class DocumentHydration
{
    public const CONFIG_SERVE_READS = 'graphcommerce/catalog_storefront/serve_reads';

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
     */
    public function rebuildFromIds(
        array $ids,
        int $totalCount,
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria,
        ?ContextInterface $context
    ): ?SearchResultsInterface {
        try {
            $store = $this->resolveStore($context);
            if (!$ids) {
                return null;
            }

            $documents = [];
            foreach ($this->storage->get($store->getCode(), $ids) as $entry) {
                $documents[(int)$entry->getId()] = $entry->getData();
            }

            $storeId = (int)$store->getId();
            $items = [];
            foreach ($ids as $id) {
                $model = isset($documents[$id]) ? $this->modelBuilder->build($documents[$id], $storeId) : null;
                if ($model === null) {
                    return null;
                }
                $items[$id] = $model;
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

    private function resolveStore(?ContextInterface $context): StoreInterface
    {
        return $context?->getExtensionAttributes()->getStore() ?? $this->storeManager->getStore();
    }
}
