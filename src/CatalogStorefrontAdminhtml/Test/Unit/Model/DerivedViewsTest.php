<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\Feeds;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\DerivedViews;
use Magento\Customer\Api\Data\GroupInterface as CustomerGroupInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Api\Data\GroupInterface as StoreGroupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class DerivedViewsTest extends TestCase
{
    public function testViewsAreActiveSortedAndReadConfigurationAtTheStoreViewScope(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([
            $this->store(2, 'nl', 'Dutch', 10, 20, true),
            $this->store(3, 'disabled', 'Disabled', 10, 20, false),
            $this->store(1, 'en', 'English', 11, 21, true),
        ]);
        $storeManager->method('getWebsite')->willReturnMap([
            [10, $this->website(10, 'main', 'Main')],
            [11, $this->website(11, 'second', 'Second')],
        ]);
        $storeManager->method('getGroup')->willReturnMap([
            ['20', $this->storeGroup(20, 'shop', 'Shop', 2)],
            ['21', $this->storeGroup(21, 'outlet', 'Outlet', 7)],
        ]);

        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn(string $path, string $scopeType, string $code): string => match ($path) {
            'general/locale/code' => $code === 'nl' ? 'nl_NL' : 'en_US',
            'currency/options/base' => 'EUR',
            'currency/options/default' => $code === 'nl' ? 'EUR' : 'USD',
            'currency/options/allow' => $code === 'nl' ? 'EUR, USD,EUR' : 'USD',
            default => '',
        });
        $scope->method('isSetFlag')->willReturnCallback(static fn(string $path, ?string $scopeType = null, mixed $code = null): bool => match ($path) {
            'catalog/storefront_documents/index_enabled' => true,
            'catalog/storefront_documents/serve_graphql' => $code === 'nl',
            'catalog/storefront_documents/serve_plp' => $code === 'en',
            default => false,
        });
        $customerGroups = $this->createStub(GroupManagementInterface::class);
        $customerGroups->method('getNotLoggedInGroup')->willReturn($this->customerGroup(0, 'NOT LOGGED IN'));
        $customerGroups->method('getLoggedInGroups')->willReturn([$this->customerGroup(1, 'General')]);
        $customerGroups->method('getDefaultGroup')->willReturn($this->customerGroup(1, 'General'));

        $provider = $this->provider($storeManager, $scope, $customerGroups);
        $views = $provider->views();

        self::assertSame(['nl', 'en'], array_column($views, 'code'));
        self::assertSame(['EUR', 'USD'], $views[0]['currency']['allowed']);
        self::assertSame(2, $views[0]['store']['rootCategoryId']);
        self::assertTrue($views[0]['indexing']);
        self::assertSame(['available' => true, 'id' => 1, 'code' => 'General', 'key' => '1'], $views[0]['defaultCustomerGroup']);
        self::assertSame(2, $views[0]['customerGroupCount']);
        self::assertTrue($views[0]['graphqlDocuments']);
        self::assertFalse($views[0]['productListingDocuments']);
        self::assertFalse($views[1]['graphqlDocuments']);
        self::assertTrue($views[1]['productListingDocuments']);
    }

    public function testGroupRowsUseTheDocumentPriceContractAndExposeItsFallback(): void
    {
        $groups = $this->createStub(GroupManagementInterface::class);
        $groups->method('getNotLoggedInGroup')->willReturn($this->customerGroup(0, 'NOT LOGGED IN'));
        $groups->method('getLoggedInGroups')->willReturn([
            $this->customerGroup(2, 'Wholesale'),
            $this->customerGroup(1, 'General'),
        ]);
        $provider = $this->provider(groupManagement: $groups);

        self::assertSame([
            'available' => true,
            'fallback' => 'all',
            'items' => [
                ['id' => 0, 'code' => 'NOT LOGGED IN', 'key' => '0'],
                ['id' => 1, 'code' => 'General', 'key' => '1'],
                ['id' => 2, 'code' => 'Wholesale', 'key' => '2'],
            ],
        ], $provider->groups());
    }

    public function testIndexerChecksAreDeduplicatedAndFailuresStayExplicit(): void
    {
        $feeds = $this->createStub(Feeds::class);
        $one = new class {
            public function getFeedName(): string
            {
                return 'Products';
            }
        };
        $two = new class {
            public function getFeedName(): string
            {
                return 'Prices';
            }
        };
        $feeds->method('byEntity')->willReturn([
            'product' => ['products' => $one, 'prices' => $two],
            'another' => ['products' => $one],
        ]);
        $valid = $this->createStub(IndexerInterface::class);
        $valid->method('getStatus')->willReturn('valid');
        $valid->method('isScheduled')->willReturn(true);
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturnCallback(static fn(string $id): IndexerInterface => match ($id) {
            'products' => $valid,
            default => throw new \RuntimeException('not configured'),
        });

        $provider = $this->provider(indexerRegistry: $registry, feeds: $feeds);

        self::assertSame([
            ['name' => 'Prices', 'id' => 'prices', 'status' => 'Unavailable', 'schedule' => 'Unavailable', 'available' => false],
            ['name' => 'Products', 'id' => 'products', 'status' => 'valid', 'schedule' => 'By schedule', 'available' => true],
        ], $provider->indexers());
    }

    public function testUnavailableGroupsAndSearchAreReportedWithoutInventingState(): void
    {
        $groups = $this->createStub(GroupManagementInterface::class);
        $groups->method('getNotLoggedInGroup')->willThrowException(new \RuntimeException('unavailable'));
        $engine = $this->createStub(EngineResolverInterface::class);
        $engine->method('getCurrentSearchEngine')->willThrowException(new \RuntimeException('unavailable'));
        $provider = $this->provider(groupManagement: $groups, engineResolver: $engine);

        self::assertSame(['available' => false, 'fallback' => 'all', 'items' => []], $provider->groups());
        self::assertSame(['available' => false], $provider->searchEngine());
    }

    private function provider(
        ?StoreManagerInterface $storeManager = null,
        ?ScopeConfigInterface $scope = null,
        ?GroupManagementInterface $groupManagement = null,
        ?EngineResolverInterface $engineResolver = null,
        ?IndexerRegistry $indexerRegistry = null,
        ?Feeds $feeds = null,
    ): DerivedViews {
        if ($storeManager === null) {
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStores')->willReturn([]);
        }
        $scope ??= $this->createStub(ScopeConfigInterface::class);
        if ($groupManagement === null) {
            $groupManagement = $this->createStub(GroupManagementInterface::class);
            $groupManagement->method('getNotLoggedInGroup')->willReturn($this->customerGroup(0, 'NOT LOGGED IN'));
            $groupManagement->method('getLoggedInGroups')->willReturn([]);
        }
        if ($engineResolver === null) {
            $engineResolver = $this->createStub(EngineResolverInterface::class);
            $engineResolver->method('getCurrentSearchEngine')->willReturn('opensearch');
        }
        $indexerRegistry ??= $this->createStub(IndexerRegistry::class);
        if ($feeds === null) {
            $feeds = $this->createStub(Feeds::class);
            $feeds->method('byEntity')->willReturn([]);
        }
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturn(true);

        return new DerivedViews(
            $storeManager,
            $scope,
            $groupManagement,
            new ProductPrice(),
            $engineResolver,
            $indexerRegistry,
            $feeds,
            $modules,
        );
    }

    private function store(int $id, string $code, string $name, int $websiteId, int $groupId, bool $active): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);
        $store->method('getCode')->willReturn($code);
        $store->method('getName')->willReturn($name);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $store->method('getStoreGroupId')->willReturn($groupId);
        $store->method('getIsActive')->willReturn($active ? 1 : 0);
        return $store;
    }

    private function website(int $id, string $code, string $name): WebsiteInterface
    {
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn($id);
        $website->method('getCode')->willReturn($code);
        $website->method('getName')->willReturn($name);
        return $website;
    }

    private function storeGroup(int $id, string $code, string $name, int $rootCategoryId): StoreGroupInterface
    {
        $group = $this->createStub(StoreGroupInterface::class);
        $group->method('getId')->willReturn($id);
        $group->method('getCode')->willReturn($code);
        $group->method('getName')->willReturn($name);
        $group->method('getRootCategoryId')->willReturn($rootCategoryId);
        return $group;
    }

    private function customerGroup(int $id, string $code): CustomerGroupInterface
    {
        $group = $this->createStub(CustomerGroupInterface::class);
        $group->method('getId')->willReturn($id);
        $group->method('getCode')->willReturn($code);
        return $group;
    }
}
