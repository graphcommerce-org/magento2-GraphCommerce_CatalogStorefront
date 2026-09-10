<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Model;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Feeds;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Factual, read-only data for the catalog views page.
 *
 * A derived view is an active Magento store view. It is not a persisted Cloud
 * view and this provider does not contact or infer the state of a Cloud service.
 */
class DerivedViews
{
    private const LOCALE = 'general/locale/code';
    private const BASE_CURRENCY = 'currency/options/base';
    private const DEFAULT_CURRENCY = 'currency/options/default';
    private const ALLOWED_CURRENCIES = 'currency/options/allow';
    private const SERVE_PLP = 'catalog/storefront_documents/serve_plp';
    private const PRODUCT_LISTING_MODULE = 'GraphCommerce_CatalogStorefrontProductFrontend';
    private const INVENTORY_MODULE = 'GraphCommerce_CatalogStorefrontInventory';
    private const REVIEW_MODULE = 'GraphCommerce_CatalogStorefrontReview';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly GroupManagementInterface $groupManagement,
        private readonly ProductPrice $productPrice,
        private readonly EngineResolverInterface $engineResolver,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly Feeds $feeds,
        private readonly ModuleManager $moduleManager,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function views(): array
    {
        $groups = $this->groups();
        $views = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!(bool)$store->getIsActive()) {
                continue;
            }
            $storeId = (int)$store->getId();
            $storeCode = (string)$store->getCode();
            $website = $this->storeManager->getWebsite((int)$store->getWebsiteId());
            $group = $this->storeManager->getGroup((string)$store->getStoreGroupId());

            $views[] = [
                'id' => $storeId,
                'code' => $storeCode,
                'name' => (string)$store->getName(),
                'website' => [
                    'id' => (int)$website->getId(),
                    'code' => (string)$website->getCode(),
                    'name' => (string)$website->getName(),
                ],
                'store' => [
                    'id' => (int)$group->getId(),
                    'code' => (string)$group->getCode(),
                    'name' => (string)$group->getName(),
                    'rootCategoryId' => (int)$group->getRootCategoryId(),
                ],
                'locale' => (string)$this->scopeConfig->getValue(self::LOCALE, ScopeInterface::SCOPE_STORE, $storeCode),
                'currency' => [
                    'base' => (string)$this->scopeConfig->getValue(self::BASE_CURRENCY, ScopeInterface::SCOPE_STORE, $storeCode),
                    'default' => (string)$this->scopeConfig->getValue(self::DEFAULT_CURRENCY, ScopeInterface::SCOPE_STORE, $storeCode),
                    'allowed' => $this->csv((string)$this->scopeConfig->getValue(
                        self::ALLOWED_CURRENCIES,
                        ScopeInterface::SCOPE_STORE,
                        $storeCode,
                    )),
                ],
                'defaultCustomerGroup' => $this->defaultGroup($storeId),
                'customerGroupsAvailable' => $groups['available'],
                'customerGroupCount' => count($groups['items']),
                'indexing' => $this->scopeConfig->isSetFlag(Config::INDEX_ENABLED),
                'graphqlDocuments' => $this->scopeConfig->isSetFlag(
                    Config::SERVE_GRAPHQL,
                    ScopeInterface::SCOPE_STORE,
                    $storeCode,
                ),
                'productListingDocuments' => $this->moduleManager->isEnabled(self::PRODUCT_LISTING_MODULE)
                    ? $this->scopeConfig->isSetFlag(self::SERVE_PLP, ScopeInterface::SCOPE_STORE, $storeCode)
                    : null,
            ];
        }
        usort($views, static fn(array $a, array $b): int => [
            $a['website']['name'],
            $a['store']['name'],
            $a['name'],
            $a['code'],
        ] <=> [
            $b['website']['name'],
            $b['store']['name'],
            $b['name'],
            $b['code'],
        ]);

        return $views;
    }

    /**
     * Customer groups become price keys in every derived view. The document
     * price writer resolves the `all` row into one priceIndex entry per key.
     *
     * @return array{available: bool, fallback: string, items: array<int, array{id: int, code: string, key: string}>}
     */
    public function groups(): array
    {
        try {
            $groups = array_merge(
                [$this->groupManagement->getNotLoggedInGroup()],
                $this->groupManagement->getLoggedInGroups(),
            );
            $items = [];
            foreach ($groups as $group) {
                $id = (int)$group->getId();
                $items[$id] = [
                    'id' => $id,
                    'code' => (string)$group->getCode(),
                    'key' => $this->productPrice->groupKey($id),
                ];
            }
            ksort($items, SORT_NUMERIC);

            return [
                'available' => true,
                'fallback' => ProductPrice::FALLBACK_GROUP,
                'items' => array_values($items),
            ];
        } catch (\Throwable) {
            return [
                'available' => false,
                'fallback' => ProductPrice::FALLBACK_GROUP,
                'items' => [],
            ];
        }
    }

    /**
     * @return array{available: bool, id?: string}
     */
    public function searchEngine(): array
    {
        try {
            $engine = trim((string)$this->engineResolver->getCurrentSearchEngine());
            return $engine === '' ? ['available' => false] : ['available' => true, 'id' => $engine];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    /**
     * Cheap indexer state only: no feed-table counts and no document-store scans.
     *
     * @return array<int, array{name: string, id: string, status: string, schedule: string, available: bool}>
     */
    public function indexers(): array
    {
        $indexers = [];
        foreach ($this->feeds->byEntity() as $entityFeeds) {
            foreach ($entityFeeds as $indexerId => $feed) {
                if (isset($indexers[$indexerId])) {
                    continue;
                }
                try {
                    $feedName = (string)$feed->getFeedName();
                } catch (\Throwable) {
                    $feedName = (string)$indexerId;
                }
                try {
                    $indexer = $this->indexerRegistry->get((string)$indexerId);
                    $indexers[$indexerId] = [
                        'name' => $feedName,
                        'id' => (string)$indexerId,
                        'status' => (string)$indexer->getStatus(),
                        'schedule' => $indexer->isScheduled() ? 'By schedule' : 'On save',
                        'available' => true,
                    ];
                } catch (\Throwable) {
                    $indexers[$indexerId] = [
                        'name' => $feedName,
                        'id' => (string)$indexerId,
                        'status' => 'Unavailable',
                        'schedule' => 'Unavailable',
                        'available' => false,
                    ];
                }
            }
        }
        usort($indexers, static fn(array $a, array $b): int => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);

        return $indexers;
    }

    /**
     * Installed document contributions. These are not independently managed content layers.
     *
     * @return array<string, array{name: string, source: string, fields: string, available: bool}>
     */
    public function contributions(): array
    {
        return [
            'content' => [
                'name' => 'Store-view content',
                'source' => 'Magento catalog feeds',
                'fields' => 'Store-scoped product, category and attribute values',
                'available' => true,
            ],
            'inventory' => [
                'name' => 'Inventory',
                'source' => 'Magento inventory stock-status feed',
                'fields' => 'The stock slice of product documents',
                'available' => $this->moduleManager->isEnabled(self::INVENTORY_MODULE),
            ],
            'reviews' => [
                'name' => 'Reviews',
                'source' => 'Magento review and rating feeds',
                'fields' => 'Visible review and rating documents',
                'available' => $this->moduleManager->isEnabled(self::REVIEW_MODULE),
            ],
        ];
    }

    /**
     * @return array{available: bool, id?: int, code?: string, key?: string}
     */
    private function defaultGroup(int $storeId): array
    {
        try {
            $group = $this->groupManagement->getDefaultGroup($storeId);
            $id = (int)$group->getId();
            return [
                'available' => true,
                'id' => $id,
                'code' => (string)$group->getCode(),
                'key' => $this->productPrice->groupKey($id),
            ];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }

    /** @return string[] */
    private function csv(string $value): array
    {
        $values = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
        return array_values(array_unique($values));
    }
}
