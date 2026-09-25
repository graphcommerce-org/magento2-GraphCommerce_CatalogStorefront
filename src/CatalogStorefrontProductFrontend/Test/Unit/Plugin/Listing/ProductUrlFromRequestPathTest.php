<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\ProductUrlFromRequestPath;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ProductUrlFromRequestPathTest extends TestCase
{
    private bool $coreAsked = false;

    private int $baseUrlReads = 0;

    private function plugin(): ProductUrlFromRequestPath
    {
        $this->coreAsked = false;
        $this->baseUrlReads = 0;
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturnCallback(function () {
            $this->baseUrlReads++;

            return 'https://shop.example/';
        });
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $request = $this->createMock(RequestInterface::class);

        return new ProductUrlFromRequestPath($storeManager, $request);
    }

    private function product(?array $document, mixed $requestPath, int $storeId = 1): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === ProductDocumentsInterface::DOCUMENT_KEY ? $document : ($key === 'request_path' ? $requestPath : null)
        );
        $product->method('getStoreId')->willReturn($storeId);
        $product->method('hasData')->willReturn(false);

        return $product;
    }

    private function url(ProductUrlFromRequestPath $plugin, Product $product, array $params = []): mixed
    {
        return $plugin->aroundGetUrl($this->createMock(ProductUrl::class), function () {
            $this->coreAsked = true;

            return 'core';
        }, $product, $params);
    }

    public function testTheUrlIsTheBaseUrlAndTheRequestPathReadOncePerStore(): void
    {
        $plugin = $this->plugin();

        $this->assertSame('https://shop.example/bag.html', $this->url($plugin, $this->product(['id' => 1], 'bag.html')));
        $this->assertSame('https://shop.example/hat.html', $this->url($plugin, $this->product(['id' => 2], 'hat.html'), ['_ignore_category' => true]));
        $this->assertSame(1, $this->baseUrlReads);
        $this->assertFalse($this->coreAsked);
    }

    public function testACoreProductOrOneWithoutAPathAsksCore(): void
    {
        $plugin = $this->plugin();

        $this->assertSame('core', $this->url($plugin, $this->product(null, 'bag.html')));
        $this->assertSame('core', $this->url($plugin, $this->product(['id' => 1], false)));
    }

    public function testACallWithRouteParametersOrAnotherStoreAsksCore(): void
    {
        $plugin = $this->plugin();

        $this->assertSame('core', $this->url($plugin, $this->product(['id' => 1], 'bag.html'), ['_query' => ['a' => 1]]));
        $this->assertSame('core', $this->url($plugin, $this->product(['id' => 1], 'bag.html', 2)));
    }
}
