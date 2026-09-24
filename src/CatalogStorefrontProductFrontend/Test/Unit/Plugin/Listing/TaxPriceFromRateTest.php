<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\TaxPrice;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\TaxPriceFromRate;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class TaxPriceFromRateTest extends TestCase
{
    private bool $coreAsked = false;

    private function plugin(): TaxPriceFromRate
    {
        $this->coreAsked = false;
        $taxPrice = $this->createMock(TaxPrice::class);
        $taxPrice->method('of')->willReturnCallback(static fn (float $amount, bool $including) => $including ? $amount * 1.21 : $amount);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->createMock(StoreInterface::class));
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('round')->willReturnCallback(static fn (float $amount) => round($amount, 2));

        return new TaxPriceFromRate($taxPrice, $storeManager, $priceCurrency);
    }

    private function product(?array $document): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with(ProductDocumentsInterface::DOCUMENT_KEY)->willReturn($document);

        return $product;
    }

    private function price(TaxPriceFromRate $plugin, Product $product, ...$arguments): mixed
    {
        return $plugin->aroundGetTaxPrice($this->createMock(CatalogHelper::class), function () {
            $this->coreAsked = true;

            return 'core';
        }, $product, ...$arguments);
    }

    public function testADocumentProductsAmountTakesTheRate(): void
    {
        $plugin = $this->plugin();

        $this->assertSame(12.1, $this->price($plugin, $this->product(['id' => 1]), 10.0, true, null, null, null, null, null, false));
        $this->assertSame(10.0, $this->price($plugin, $this->product(['id' => 1]), 10.0, false, null, null, null, null, null, false));
        $this->assertFalse($this->coreAsked);
    }

    public function testARoundedCallRoundsTheResult(): void
    {
        $this->assertSame(12.1, $this->price($this->plugin(), $this->product(['id' => 1]), 10.004, true));
    }

    public function testACoreProductAsksCore(): void
    {
        $this->assertSame('core', $this->price($this->plugin(), $this->product(null), 10.0, true));
    }

    public function testACallWithItsOwnAddressOrWithoutTheFlagAsksCore(): void
    {
        $plugin = $this->plugin();
        $this->assertSame('core', $this->price($plugin, $this->product(['id' => 1]), 10.0, true, 'address'));
        $this->assertSame('core', $this->price($plugin, $this->product(['id' => 1]), 10.0, null));
    }
}
