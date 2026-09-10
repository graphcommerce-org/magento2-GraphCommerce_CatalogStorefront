<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit;

use GraphCommerce\CatalogStorefrontAdminhtml\Controller\Adminhtml\Views\Index;
use Magento\Framework\App\Action\HttpGetActionInterface;
use PHPUnit\Framework\TestCase;

class AdminSurfaceTest extends TestCase
{
    public function testRouteMenuAclAndLayoutDeclareOneReadOnlyPage(): void
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
        self::assertSame(
            'GraphCommerce\\CatalogStorefrontAdminhtml\\Block\\Adminhtml\\Views',
            (string)$layout->body->referenceContainer->block['class'],
        );
        self::assertSame(
            'GraphCommerce_CatalogStorefrontAdminhtml::css/views.css',
            (string)$layout->head->css['src'],
        );
        self::assertContains(HttpGetActionInterface::class, class_implements(Index::class));
        self::assertSame(
            [$module . '/Controller/Adminhtml/Views/Index.php'],
            glob($module . '/Controller/Adminhtml/*/*.php'),
        );
    }

    public function testEveryDynamicTemplateValueUsesTheEscaper(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 2) . '/view/adminhtml/templates/views.phtml');
        self::assertStringNotContainsString('<?= $view', $template);
        self::assertStringNotContainsString('<?= $group', $template);
        self::assertStringNotContainsString('<?= $indexer', $template);
        self::assertStringContainsString('$escaper->escapeHtml(', $template);
        self::assertStringContainsString('$escaper->escapeHtmlAttr(', $template);
        foreach (['Catalog Views', 'Catalog Sources', 'Price Books', 'Catalog Layers', 'Catalog Policies'] as $heading) {
            self::assertStringContainsString($heading, $template);
        }
        self::assertStringNotContainsString('<h2 id="catalog-storefront-inventory', $template);
        self::assertStringNotContainsString('<h2 id="catalog-storefront-reviews', $template);
        self::assertStringNotContainsString('Synchronization Checks', $template);
        foreach (['Create Catalog View', 'Add Catalog Source', 'Add Price Book', 'Add Catalog Layer', 'Add Policy'] as $action) {
            self::assertStringContainsString($action, $template);
        }
        self::assertSame(5, substr_count($template, '<section class="catalog-storefront-panel"'));
        self::assertStringContainsString('popovertarget=', $template);
        self::assertStringContainsString('This management workflow is not available yet.', $template);
        self::assertStringContainsString('MODULE ENABLED', $template);
        self::assertStringContainsString('MODULE UNAVAILABLE', $template);
    }

    private function xml(string $path): \SimpleXMLElement
    {
        $xml = simplexml_load_file($path);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }
}
