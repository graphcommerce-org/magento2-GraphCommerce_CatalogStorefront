<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Ui\DataProvider;

use GraphCommerce\CatalogStorefrontAdminhtml\Model\DerivedViews;
use GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider\OverviewDataProvider;
use Magento\Framework\Api\Filter;
use PHPUnit\Framework\TestCase;

class OverviewDataProviderTest extends TestCase
{
    public function testReturnsConfiguredDatasetUsingMagentoListingContract(): void
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
                'origin' => 'Store view Default Store View (ID 1)',
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
