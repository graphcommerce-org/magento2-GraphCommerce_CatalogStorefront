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
        self::assertCount(9, $templates);

        foreach ($templates as $template) {
            $contents = (string)file_get_contents($template);
            self::assertStringNotContainsString(' html=', $contents, $template);
            self::assertStringNotContainsString('html:', $contents, $template);
            self::assertStringNotContainsString('unsanitized', strtolower($contents), $template);
        }

        $feed = (string)file_get_contents($directory . '/feed.html');
        self::assertStringContainsString("with: { data: \$col.getLabel(\$row()), as: '\$feed' }", $feed);
        self::assertStringContainsString('text="$feed.value"', $feed);

        $more = (string)file_get_contents($module . '/view/adminhtml/web/js/grid/columns/more.js');
        self::assertStringContainsString("'Magento_Ui/js/grid/columns/column'", $more);
        self::assertStringContainsString("'Magento_Ui/js/modal/alert'", $more);
    }
}
