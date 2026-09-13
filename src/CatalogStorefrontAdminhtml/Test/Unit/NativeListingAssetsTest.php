<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit;

use PHPUnit\Framework\TestCase;

final class NativeListingAssetsTest extends TestCase
{
    public function testListingExtendsMagentoGridAndKeepsItsProviderDrivenRenderLoop(): void
    {
        $module = dirname(__DIR__, 2);
        $component = (string)file_get_contents($module . '/view/adminhtml/web/js/grid/listing.js');
        $template = (string)file_get_contents($module . '/view/adminhtml/web/template/grid/listing.html');

        self::assertStringContainsString("'Magento_Ui/js/grid/listing'", $component);
        self::assertStringContainsString("'Magento_Ui/js/modal/alert'", $component);
        self::assertStringContainsString("template: 'GraphCommerce_CatalogStorefrontAdminhtml/grid/listing'", $component);
        self::assertStringContainsString('getRecordCount: function ()', $component);

        self::assertStringContainsString('class="catalog-overview-panel"', $template);
        self::assertStringContainsString('class="data-grid" data-role="grid"', $template);
        self::assertStringContainsString('each="data: getVisible(), as: \'$col\'"', $template);
        self::assertStringContainsString('repeat="foreach: rows, item: \'$row\'"', $template);
        self::assertStringContainsString('template="getBody()"', $template);
        self::assertStringContainsString('ifnot="hasData()"', $template);
    }

    public function testCellTemplatesUseTextBindingsAndNativeModalAction(): void
    {
        $module = dirname(__DIR__, 2);
        $directory = $module . '/view/adminhtml/web/template/grid/cells';
        $templates = glob($directory . '/*.html');
        self::assertIsArray($templates);
        // Check every cell template, including newly added columns, without fixing their number.
        self::assertNotEmpty($templates);

        foreach ($templates as $template) {
            $contents = (string)file_get_contents($template);
            self::assertStringNotContainsString(' html=', $contents, $template);
            self::assertStringNotContainsString('html:', $contents, $template);
            self::assertStringNotContainsString('unsanitized', strtolower($contents), $template);
        }

        $feed = (string)file_get_contents($directory . '/feed.html');
        self::assertStringContainsString('with: $col.getLabel($row())', $feed);
        self::assertStringContainsString('text="$data.value"', $feed);

        $more = (string)file_get_contents($module . '/view/adminhtml/web/js/grid/columns/more.js');
        self::assertStringContainsString("'Magento_Ui/js/grid/columns/column'", $more);
        self::assertStringContainsString("'Magento_Ui/js/modal/alert'", $more);
        self::assertStringContainsString("headerTmpl: 'GraphCommerce_CatalogStorefrontAdminhtml/grid/columns/more'", $more);
    }

    public function testCustomHeadersKeepNativeGridBehaviorAndAccessibleLabels(): void
    {
        $module = dirname(__DIR__, 2);
        $headers = $module . '/view/adminhtml/web/template/grid/columns';
        $more = (string)file_get_contents($headers . '/more.html');
        $number = (string)file_get_contents($headers . '/number.html');
        $info = (string)file_get_contents($headers . '/info.html');
        $infoComponent = (string)file_get_contents($module . '/view/adminhtml/web/js/grid/columns/info.js');

        foreach ([$more, $number, $info] as $header) {
            self::assertStringContainsString('class="data-grid-th', $header);
            self::assertStringContainsString('click="sort"', $header);
            self::assertStringContainsString('_sortable: sortable', $header);
            self::assertStringContainsString("_ascend: sorting === 'asc'", $header);
            self::assertStringContainsString("_descend: sorting === 'desc'", $header);
        }
        self::assertStringContainsString('class="abs-visually-hidden" translate="label"', $more);
        self::assertStringContainsString('class="data-grid-th catalog-overview-number"', $number);
        self::assertStringContainsString('clickBubble: false', $info);
        self::assertStringContainsString("'Magento_Ui/js/modal/alert'", $infoComponent);
        self::assertStringContainsString('content: $t(this.infoText)', $infoComponent);
    }

    public function testNumericAndPolicyHeaderConfigurationUsesTheCustomNativeColumns(): void
    {
        $module = dirname(__DIR__, 2);
        $directory = $module . '/view/adminhtml/ui_component';
        $files = glob($directory . '/*.xml');
        self::assertIsArray($files);
        self::assertCount(7, $files);

        $all = '';
        foreach ($files as $file) {
            $xml = simplexml_load_file($file);
            self::assertInstanceOf(\SimpleXMLElement::class, $xml);
            $all .= (string)file_get_contents($file);
        }
        self::assertSame(6, substr_count($all, '<item name="dataset" xsi:type="string">'));
        self::assertSame(
            substr_count($all, '<class name="catalog-overview-number">true</class>'),
            substr_count($all, '<headerTmpl>GraphCommerce_CatalogStorefrontAdminhtml/grid/columns/number</headerTmpl>'),
        );
        self::assertSame(
            substr_count($all, '<bodyTmpl>GraphCommerce_CatalogStorefrontAdminhtml/grid/cells/feed</bodyTmpl>'),
            substr_count($all, '<class name="catalog-overview-feed-column">true</class>'),
        );

        $policies = (string)file_get_contents($directory . '/catalog_storefront_policies_listing.xml');
        self::assertStringContainsString(
            '<column name="trigger" component="GraphCommerce_CatalogStorefrontAdminhtml/js/grid/columns/info"',
            $policies,
        );
        self::assertStringContainsString(
            'Stored policies and their resource relationships.',
            $policies,
        );
    }
}
