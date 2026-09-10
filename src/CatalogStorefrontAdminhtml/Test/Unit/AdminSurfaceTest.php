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
        self::assertStringContainsString('$escaper->escapeUrl(', $template);
    }

    private function xml(string $path): \SimpleXMLElement
    {
        $xml = simplexml_load_file($path);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        return $xml;
    }
}
