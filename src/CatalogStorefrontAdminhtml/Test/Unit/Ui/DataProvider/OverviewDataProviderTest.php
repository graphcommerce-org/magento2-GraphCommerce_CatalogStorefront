<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Ui\DataProvider;

use GraphCommerce\CatalogStorefrontAdminhtml\Model\DerivedViews;
use GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider\OverviewDataProvider;
use Magento\Framework\Api\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OverviewDataProviderTest extends TestCase
{
    #[DataProvider('nativeDataSourceNames')]
    public function testDerivesDatasetFromExactNativeDataSourceName(
        string $name,
        int $expectedCount,
        ?string $identityField,
        ?string $identityValue,
    ): void {
        $provider = new OverviewDataProvider(
            $name,
            $identityField ?? 'id',
            $identityField ?? 'id',
            $this->derivedViews(),
        );

        $data = $provider->getData();
        self::assertSame($expectedCount, $data['totalRecords']);
        self::assertCount($expectedCount, $data['items']);
        if ($identityField !== null) {
            self::assertSame($identityValue, $data['items'][0][$identityField]);
        }
    }

    public function testReturnsConfiguredDatasetUsingMagentoListingContract(): void
    {
        $derived = $this->derivedViews();

        $provider = new OverviewDataProvider(
            'catalog_sources_data_source',
            'code',
            'code',
            $derived,
            [],
            ['config' => ['dataset' => 'sources']],
        );

        self::assertSame('catalog_sources_data_source', $provider->getName());
        self::assertSame('code', $provider->getPrimaryFieldName());
        self::assertSame('code', $provider->getRequestFieldName());
        self::assertSame([
            'items' => [[
                'code' => 'default',
                'type' => 'Magento Store View Catalog',
                'origin' => 'Store view default (ID 1)',
                'locale' => 'en_US',
                'feedProducts' => $this->emptyFeed(),
                'feedCategories' => $this->emptyFeed(),
                'feedAttributes' => $this->emptyFeed(),
            ]],
            'totalRecords' => 1,
        ], $provider->getData());
        self::assertSame(1, $provider->count());

        // Magento's optional listing processors must remain harmless if a
        // request supplies their parameters although this dashboard exposes
        // no filters, sorting or paging controls.
        $provider->addFilter($this->createStub(Filter::class));
        $provider->addOrder('code', 'DESC');
        $provider->setLimit(2, 20);
        $provider->addField('code');
        self::assertSame(1, $provider->getData()['totalRecords']);
    }

    public function testRejectsMissingOrUnknownDataset(): void
    {
        $derived = $this->createStub(DerivedViews::class);
        $this->expectException(\InvalidArgumentException::class);
        new OverviewDataProvider('invalid', 'id', 'id', $derived, [], ['config' => ['dataset' => 'sync']]);
    }

    /** @return array<string, array{string, int, string|null, string|null}> */
    public static function nativeDataSourceNames(): array
    {
        return [
            'views' => ['catalog_storefront_views_listing_data_source', 1, 'id', 'default'],
            'sources' => ['catalog_storefront_sources_listing_data_source', 1, 'code', 'default'],
            'books' => ['catalog_storefront_books_listing_data_source', 2, 'id', 'all'],
            'layers' => ['catalog_storefront_layers_listing_data_source', 0, null, null],
            'policies' => ['catalog_storefront_policies_listing_data_source', 0, null, null],
        ];
    }

    private function derivedViews(): DerivedViews
    {
        $derived = $this->createStub(DerivedViews::class);
        $derived->method('views')->willReturn([[
            'id' => 1,
            'code' => 'default',
            'name' => 'Default Store View',
            'website' => ['code' => 'base'],
            'store' => ['rootCategoryId' => 2],
            'locale' => 'en_US',
            'currency' => ['base' => 'USD'],
        ]]);
        $derived->method('groups')->willReturn([
            'available' => true,
            'fallback' => 'all',
            'items' => [['id' => 0, 'code' => 'NOT LOGGED IN', 'key' => 'customer_group_0']],
        ]);
        $derived->method('contributions')->willReturn([]);
        return $derived;
    }

    /** @return array<string, mixed> */
    private function emptyFeed(): array
    {
        return [
            'value' => '—',
            'hasBadge' => false,
            'badgeLabel' => '',
            'badgeBg' => '#F1F1F1',
            'badgeBorder' => 'transparent',
            'badgeFg' => '#303030',
        ];
    }
}
