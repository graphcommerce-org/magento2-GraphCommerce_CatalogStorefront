<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit;

use GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Views\Index;
use Magento\Framework\App\Action\HttpGetActionInterface;
use PHPUnit\Framework\TestCase;

class AdminSurfaceTest extends TestCase
{
    public function testRouteMenuAclAndLayoutDeclareSixStoredListings(): void
    {
        $module = dirname(__DIR__, 2);
        $route = $this->xml($module . '/etc/adminhtml/routes.xml');
        $menu = $this->xml($module . '/etc/adminhtml/menu.xml');
        $acl = $this->xml($module . '/etc/acl.xml');
        $layout = $this->xml($module . '/view/adminhtml/layout/catalog_storefront_views_index.xml');

        self::assertSame('catalog_storefront', (string)$route->router->route['frontName']);
        self::assertSame('catalog_storefront/views/index', (string)$menu->menu->add['action']);
        self::assertSame(Index::ADMIN_RESOURCE, (string)$menu->menu->add['resource']);
        self::assertSame(
            Index::ADMIN_RESOURCE,
            (string)$acl->acl->resources->resource->resource->resource['id'],
        );
        $expected = [
            'catalog_storefront_views_listing' => 'catalogViews',
            'catalog_storefront_sources_listing' => 'sources',
            'catalog_storefront_books_listing' => 'books',
            'catalog_storefront_stocks_listing' => 'stocks',
            'catalog_storefront_layers_listing' => 'layers',
            'catalog_storefront_policies_listing' => 'policies',
        ];
        $declared = $layout->xpath('//uiComponent');
        self::assertIsArray($declared);
        self::assertSame(array_keys($expected), array_map(
            static fn(\SimpleXMLElement $component): string => (string)$component['name'],
            $declared,
        ));
        self::assertSame([], $layout->xpath('//block'));
        foreach ($expected as $component => $dataset) {
            $path = $module . '/view/adminhtml/ui_component/' . $component . '.xml';
            self::assertFileExists($path);
            $contents = (string)file_get_contents($path);
            self::assertStringContainsString(
                'GraphCommerce\\CatalogStorefrontAdminhtml\\Ui\\DataProvider\\RegistryListing',
                $contents,
            );
            self::assertStringContainsString('<item name="dataset" xsi:type="string">' . $dataset . '</item>', $contents);
            self::assertStringNotContainsString('<listingToolbar', $contents);
            self::assertStringNotContainsString('<massaction', $contents);
        }
        $form = (string)file_get_contents($module . '/view/adminhtml/ui_component/catalog_storefront_resource_form.xml');
        self::assertStringContainsString('<aclResource>GraphCommerce_CatalogStorefrontAdminhtml::views</aclResource>', $form);
        self::assertContains(HttpGetActionInterface::class, class_implements(Index::class));
        foreach ([\GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Resource\Save::class, \GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Resource\Delete::class] as $controller) {
            self::assertContains(\Magento\Framework\App\Action\HttpPostActionInterface::class, class_implements($controller));
            self::assertNotSame(Index::ADMIN_RESOURCE, $controller::ADMIN_RESOURCE);
        }
    }

    private function xml(string $path): \SimpleXMLElement
    {
        $xml = simplexml_load_file($path);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }
}
