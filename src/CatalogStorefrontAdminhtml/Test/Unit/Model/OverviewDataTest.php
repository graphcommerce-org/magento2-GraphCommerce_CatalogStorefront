<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontAdminhtml\Model\OverviewData;
use PHPUnit\Framework\TestCase;

class OverviewDataTest extends TestCase
{
    public function testBuildMapsOnlyFactualMagentoDataToTheDesignShape(): void
    {
        $overview = OverviewData::build(
            [[
                'id' => 3,
                'code' => 'nl_store',
                'name' => 'Netherlands',
                'website' => ['code' => 'base'],
                'store' => ['rootCategoryId' => 2],
                'locale' => 'nl_NL',
                'currency' => ['base' => 'EUR'],
            ]],
            [
                'available' => true,
                'fallback' => 'all',
                'items' => [
                    ['id' => 0, 'code' => 'NOT LOGGED IN', 'key' => 'customer_group_0'],
                    ['id' => 1, 'code' => 'General', 'key' => 'customer_group_1'],
                ],
            ],
            [
                'content' => [
                    'name' => 'Store-view content',
                    'source' => 'Magento catalog feeds',
                    'fields' => 'Product and category values',
                    'available' => true,
                ],
                'reviews' => [
                    'name' => 'Reviews',
                    'source' => 'Magento review feeds',
                    'fields' => 'Reviews and ratings',
                    'available' => false,
                ],
            ],
        );

        self::assertSame([
            'catalogViews',
            'sources',
            'stocks',
            'books',
            'layers',
            'policies',
            'viewTip',
            'bookTip',
            'layerTip',
            'policyTip',
            'triggerTip',
        ], array_keys($overview));
        self::assertSame('nl_store', $overview['catalogViews'][0]['source']);
        self::assertSame('—', $overview['catalogViews'][0]['protection']);
        self::assertSame('Customer group pricing', $overview['catalogViews'][0]['bookMode']);
        self::assertSame('NOT LOGGED IN, General', $overview['catalogViews'][0]['bookList']);
        self::assertSame('—', $overview['catalogViews'][0]['policies']);
        self::assertSame('—', $overview['catalogViews'][0]['layers']);
        self::assertSame('nl_NL', $overview['sources'][0]['locale']);
        self::assertSame('Store view nl_store (ID 3)', $overview['sources'][0]['origin']);
        self::assertSame('—', $overview['sources'][0]['feedProducts']['value']);
        self::assertFalse($overview['sources'][0]['feedProducts']['hasBadge']);

        self::assertSame(['all', 'customer_group_0', 'customer_group_1'], array_column($overview['books'], 'id'));
        self::assertSame([0, 1, 1], array_column($overview['books'], 'depth'));
        self::assertSame(['0px', '12px', '12px'], array_column($overview['books'], 'indent'));
        self::assertSame(['Fallback', 'Child', 'Child'], array_column($overview['books'], 'role'));
        self::assertSame('EUR', $overview['books'][2]['currency']);
        self::assertSame('', $overview['books'][0]['currencyNote']);
        self::assertSame('', $overview['books'][2]['currencyNote']);
        self::assertSame('—', $overview['books'][2]['feedPrices']['value']);

        self::assertSame([], $overview['layers']);
        self::assertSame([], $overview['policies']);
        foreach (['viewTip', 'bookTip', 'layerTip', 'policyTip', 'triggerTip'] as $tip) {
            self::assertSame(['lines'], array_keys($overview[$tip]));
            self::assertNotEmpty($overview[$tip]['lines']);
            foreach ($overview[$tip]['lines'] as $line) {
                self::assertArrayHasKey('text', $line);
            }
        }
    }

    public function testStockPolicyLinksOnlyViewsThatHideOutOfStockProducts(): void
    {
        $views = [];
        foreach (['default' => false, 'retail' => false, 'wholesale' => true] as $code => $show) {
            $views[] = ['id' => count($views) + 1, 'code' => $code, 'name' => $code,
                'showOutOfStock' => $show, 'currency' => ['base' => 'USD']];
        }
        $result = OverviewData::build($views, ['available' => false, 'fallback' => 'all', 'items' => []], []);
        self::assertSame(['in-stock-only', 'in-stock-only', '—'], array_column($result['catalogViews'], 'policies'));
        self::assertCount(1, $result['policies']);
        self::assertSame(2, $result['policies'][0]['views']);
        self::assertSame('MAGENTO CONFIG', $result['policies'][0]['type']);
        self::assertSame('is_in_stock EQUALS 1', $result['policies'][0]['filter']);
    }

    public function testUnavailableOptionalDataNeverInventsCountsOrAssignments(): void
    {
        $overview = OverviewData::build(
            [[
                'id' => 1,
                'code' => 'default',
                'name' => 'Default Store View',
                'website' => ['code' => 'base'],
                'store' => ['rootCategoryId' => 2],
                'locale' => '',
                'currency' => ['base' => ''],
            ]],
            ['available' => false, 'fallback' => 'all', 'items' => []],
            [],
        );

        self::assertSame('—', $overview['catalogViews'][0]['bookList']);
        self::assertSame('—', $overview['catalogViews'][0]['layers']);
        self::assertSame('—', $overview['sources'][0]['locale']);
        self::assertSame('—', $overview['books'][0]['currency']);
        self::assertCount(1, $overview['books']);
        self::assertSame([], $overview['layers']);
        self::assertSame([], $overview['policies']);
    }
}
