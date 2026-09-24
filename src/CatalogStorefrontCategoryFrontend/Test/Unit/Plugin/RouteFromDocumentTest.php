<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Plugin\RouteFromDocument;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\CompositeUrlFinder;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;
use PHPUnit\Framework\TestCase;

class RouteFromDocumentTest extends TestCase
{
    /** @var array<string, mixed> the filter the storage was asked with */
    private array $filter = [];

    private bool $coreAsked = false;

    private function plugin(string $suffix, array $documents): RouteFromDocument
    {
        $this->filter = [];
        $this->coreAsked = false;

        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->method('find')->willReturnCallback(function (string $entity, string $store, array $filter) use ($documents) {
            $this->filter = $filter;
            $found = array_filter($documents, static fn (array $document) => $document['urlPath'] === $filter['urlPath']);

            return ['documents' => $found, 'total' => count($found)];
        });

        $mode = $this->createMock(Mode::class);
        $mode->method('listing')->willReturn(true);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($suffix);

        $factory = $this->createMock(UrlRewriteFactory::class);
        $factory->method('create')->willReturnCallback(static fn () => new UrlRewrite([], new Json()));

        return new RouteFromDocument($storage, $this->createMock(CategoryDocuments::class), $mode, $storeManager, $config, $factory);
    }

    private function route(RouteFromDocument $plugin, string $path): ?UrlRewrite
    {
        return $plugin->aroundFindOneByData(
            $this->createMock(CompositeUrlFinder::class),
            function () {
                $this->coreAsked = true;

                return null;
            },
            ['request_path' => $path, 'store_id' => 1]
        );
    }

    public function testACategoryPathRoutesToTheCategoryView(): void
    {
        $plugin = $this->plugin('.html', [10 => ['id' => 10, 'urlPath' => 'clubkleding/hurley']]);

        $rewrite = $this->route($plugin, 'clubkleding/hurley.html');

        $this->assertSame('catalog/category/view/id/10', $rewrite->getTargetPath());
        $this->assertSame('clubkleding/hurley.html', $rewrite->getRequestPath());
        $this->assertSame(0, $rewrite->getRedirectType());
        $this->assertSame(['urlPath' => 'clubkleding/hurley'], $this->filter);
    }

    public function testTheTargetPathOfTheAnsweredRouteHasNoRewrite(): void
    {
        $plugin = $this->plugin('', [10 => ['id' => 10, 'urlPath' => 'clubkleding/hurley']]);
        $this->route($plugin, 'clubkleding/hurley');

        $this->assertNull($this->route($plugin, 'catalog/category/view/id/10'));
        $this->assertFalse($this->coreAsked);
    }

    public function testAPathWithoutTheCategorySuffixAsksCore(): void
    {
        $plugin = $this->plugin('.html', [10 => ['id' => 10, 'urlPath' => 'clubkleding/hurley']]);

        $this->assertNull($this->route($plugin, 'clubkleding/hurley'));
        $this->assertTrue($this->coreAsked);
    }

    public function testAPathWithoutACategoryAsksCore(): void
    {
        $plugin = $this->plugin('', [10 => ['id' => 10, 'urlPath' => 'clubkleding/hurley']]);

        $this->assertNull($this->route($plugin, 'hurley-shirt'));
        $this->assertTrue($this->coreAsked);
    }

    public function testALookupByEntityAsksCore(): void
    {
        $plugin = $this->plugin('', []);

        $plugin->aroundFindOneByData($this->createMock(CompositeUrlFinder::class), function () {
            $this->coreAsked = true;

            return null;
        }, ['entity_type' => 'product', 'entity_id' => 5, 'store_id' => 1]);

        $this->assertTrue($this->coreAsked);
    }
}
