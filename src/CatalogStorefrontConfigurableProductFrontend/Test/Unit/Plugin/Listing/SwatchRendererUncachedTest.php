<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Plugin\Listing\SwatchRendererUncached;
use Magento\Catalog\Model\Product;
use Magento\Swatches\Block\Product\Renderer\Configurable;
use PHPUnit\Framework\TestCase;

class SwatchRendererUncachedTest extends TestCase
{
    /** @var array<string, mixed> the renderer block's data */
    private array $data = [];

    private function renderer(): Configurable
    {
        $renderer = $this->createMock(Configurable::class);
        $renderer->method('hasData')->willReturnCallback(fn (string $key) => array_key_exists($key, $this->data));
        $renderer->method('getData')->willReturnCallback(fn (string $key) => $this->data[$key] ?? null);
        $renderer->method('setData')->willReturnCallback(function (string $key, $value) use ($renderer) {
            $this->data[$key] = $value;

            return $renderer;
        });
        $renderer->method('unsetData')->willReturnCallback(function (string $key) use ($renderer) {
            unset($this->data[$key]);

            return $renderer;
        });

        return $renderer;
    }

    private function product(?array $document): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with(ProductDocumentsInterface::DOCUMENT_KEY)->willReturn($document);

        return $product;
    }

    public function testADocumentProductRendersUncached(): void
    {
        $renderer = $this->renderer();

        (new SwatchRendererUncached())->afterSetProduct($renderer, $renderer, $this->product(['id' => 1]));

        $this->assertFalse($this->data['cache_lifetime']);
    }

    public function testACoreProductAfterADocumentProductGetsTheDefaultBack(): void
    {
        $renderer = $this->renderer();
        $plugin = new SwatchRendererUncached();

        $plugin->afterSetProduct($renderer, $renderer, $this->product(['id' => 1]));
        $plugin->afterSetProduct($renderer, $renderer, $this->product(null));

        $this->assertArrayNotHasKey('cache_lifetime', $this->data);
    }

    public function testACoreProductAfterADocumentProductGetsTheLayoutLifetimeBack(): void
    {
        $this->data['cache_lifetime'] = 600;
        $renderer = $this->renderer();
        $plugin = new SwatchRendererUncached();

        $plugin->afterSetProduct($renderer, $renderer, $this->product(['id' => 1]));
        $this->assertFalse($this->data['cache_lifetime']);

        $plugin->afterSetProduct($renderer, $renderer, $this->product(null));
        $this->assertSame(600, $this->data['cache_lifetime']);
    }

    public function testACoreProductAloneIsUntouched(): void
    {
        $renderer = $this->renderer();

        (new SwatchRendererUncached())->afterSetProduct($renderer, $renderer, $this->product(null));

        $this->assertSame([], $this->data);
    }
}
