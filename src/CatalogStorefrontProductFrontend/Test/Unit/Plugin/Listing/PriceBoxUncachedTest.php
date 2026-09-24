<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\PriceBoxUncached;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Render\PriceBox;
use Magento\Framework\Pricing\Render\RendererPool;
use PHPUnit\Framework\TestCase;

class PriceBoxUncachedTest extends TestCase
{
    private function product(?array $document): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with(ProductDocumentsInterface::DOCUMENT_KEY)->willReturn($document);

        return $product;
    }

    public function testADocumentProductsPriceBoxIsUncached(): void
    {
        $box = $this->createMock(PriceBox::class);
        $box->expects($this->once())->method('setData')->with('cache_lifetime', false);

        $result = (new PriceBoxUncached())->afterCreatePriceRender(
            $this->createMock(RendererPool::class),
            $box,
            'final_price',
            $this->product(['id' => 1])
        );

        $this->assertSame($box, $result);
    }

    public function testACoreProductsPriceBoxKeepsItsCache(): void
    {
        $box = $this->createMock(PriceBox::class);
        $box->expects($this->never())->method('setData');

        (new PriceBoxUncached())->afterCreatePriceRender(
            $this->createMock(RendererPool::class),
            $box,
            'final_price',
            $this->product(null)
        );
    }
}
