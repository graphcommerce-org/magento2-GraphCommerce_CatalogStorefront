<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Ui\DataProvider;

use GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider\RegistryListing;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\SourceFeedCounts;
use GraphCommerce\CatalogStorefront\Model\Registry\{Definition, Platform, NativeResources};
use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

final class RegistryListingTest extends TestCase
{
    public function testViewGlobalLayersRespectSourceLocaleAndNativeLegacyScope(): void
    {
        $source = static fn($id, $type, $locale) => ['id' => $id, 'code' => 'source-' . $id, 'type' => $type, 'locale' => $locale, 'enabled' => 1];
        $layer = static fn($id, $code, $sourceId, $locale = '', $priority = 10) => ['id' => $id, 'code' => $code, 'type' => 'generic', 'scope' => 'global', 'enabled' => 1, 'source_id' => $sourceId, 'locale' => $locale, 'priority' => $priority];
        $view = static fn($id, $sourceId, $links = []) => ['id' => $id, 'code' => 'view-' . $id, 'type' => 'generic', 'enabled' => 1, 'source_id' => $sourceId,
            'stock_id' => null, 'protection' => 'public', 'book_mode' => 'all', 'book_ids' => [], 'layer_ids' => array_map(static fn($id) => ['resource_id' => $id], $links), 'policy_ids' => []];
        $records = [
            'sources' => [$source(1, 'generic', 'en_US'), $source(2, 'generic', 'en_US'), $source(3, 'generic', 'de_DE'), $source(4, 'platform_store_view', 'en_US'), $source(5, 'platform_store_view', 'de_DE')],
            'layers' => [$layer(11, 'own-en', 1, 'en_US', 20), $layer(10, 'own-all-locales', 1), $layer(12, 'other-source', 2), $layer(13, 'wrong-locale', 1, 'de_DE'),
                $layer(14, 'legacy-en', null, 'en_US'), $layer(15, 'legacy-all-locales', null, '', 5), $layer(16, 'legacy-de', null, 'de_DE'),
                array_replace($layer(17, 'disabled', 1), ['enabled' => 0]), array_replace($layer(18, 'selected', null), ['scope' => 'view']),
                array_replace($layer(19, 'reviews', null), ['type' => 'platform_reviews'])],
            'views' => [$view(1, 1, [18, 11, 12]), $view(2, 2), $view(3, 3), $view(4, 4, [18]), $view(5, 5), $view(6, 99)],
        ];
        $repository = $this->createStub(ConfigurationInterface::class); $repository->method('all')->willReturnCallback(static fn($kind) => $records[$kind] ?? []);
        $platform = $this->createStub(Platform::class); $platform->method('name')->willReturn('Magento');
        $counts = $this->createMock(SourceFeedCounts::class); $counts->expects(self::never())->method('get'); $counts->expects(self::never())->method('stocks');
        $native = $this->createMock(NativeResources::class); $native->expects(self::never())->method('stockSnapshot');
        $stores = $this->createMock(StoreManagerInterface::class); $stores->expects(self::never())->method('getStore');
        $listing = new RegistryListing('catalog_storefront_views_listing_data_source', 'id', 'id', $repository, new Definition($platform), $counts, $native, $stores, $this->createStub(ScopeConfigInterface::class));
        $result = $listing->getData();
        self::assertSame(6, $result['totalRecords']);
        self::assertSame([
            'own-all-locales, reviews, own-en, selected', 'other-source, reviews', 'reviews',
            'legacy-all-locales, legacy-en, reviews, selected', 'legacy-all-locales, legacy-de, reviews', '',
        ], array_column($result['items'], 'layers'));
    }
    public function testLayerReverseConnectionsRespectSourceLocaleAndViewSideSelection(): void
    {
        $records = [
            'sources'=>[['id'=>1,'type'=>'generic','locale'=>'en_US'],['id'=>2,'type'=>'generic','locale'=>'nl_NL']],
            'layers'=>[
                ['id'=>10,'name'=>'Manual','code'=>'manual','type'=>'generic','enabled'=>1,'scope'=>'view','source_id'=>1,'locale'=>'','fields'=>[]],
                ['id'=>11,'name'=>'Automatic','code'=>'automatic','type'=>'generic','enabled'=>1,'scope'=>'global','source_id'=>1,'locale'=>'en_US','fields'=>[]],
                ['id'=>12,'name'=>'Unconnected','code'=>'unconnected','type'=>'generic','enabled'=>1,'scope'=>'view','source_id'=>1,'locale'=>'','fields'=>[]],
            ],
            'views'=>[['id'=>1,'code'=>'english','source_id'=>1,'layer_ids'=>[['resource_id'=>10]]],['id'=>2,'code'=>'dutch','source_id'=>2,'layer_ids'=>[]]],
        ];
        $repository=$this->createStub(ConfigurationInterface::class);$repository->method('all')->willReturnCallback(static fn($kind)=>$records[$kind]??[]);
        $platform=$this->createStub(Platform::class);$platform->method('name')->willReturn('Magento');
        $listing=new RegistryListing('catalog_storefront_layers_listing_data_source','id','id',$repository,new Definition($platform),$this->createStub(SourceFeedCounts::class),$this->createStub(NativeResources::class),$this->createStub(StoreManagerInterface::class),$this->createStub(ScopeConfigInterface::class));
        $rows=$listing->getData()['items'];
        self::assertSame(['english','english',''],array_column($rows,'linkedViews'));
        self::assertSame(['Connected from individual Views','Automatic for matching Views','Connected from individual Views'],array_column($rows,'availability'));
        self::assertSame(['Unknown','Unknown','Unknown'],array_map(static fn($row)=>$row['feedRecords']['value'],$rows));
    }
}
