<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Read\DisplayPrice;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Weee\Helper\Data as WeeeHelper;
use PHPUnit\Framework\TestCase;

class DisplayPriceTest extends TestCase
{
    private function displayPrice(bool $priceIncludesTax, bool $displayIncludingTax, float $rate): DisplayPrice
    {
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('convert')->willReturnCallback(static fn($amount) => $amount * $rate);
        $priceCurrency->method('convertAndRound')->willReturnCallback(static fn($amount) => round($amount * $rate, 2));
        $taxHelper = $this->createMock(TaxHelper::class);
        $taxHelper->method('priceIncludesTax')->willReturn($priceIncludesTax);
        $taxHelper->method('displayPriceIncludingTax')->willReturn($displayIncludingTax);
        $taxHelper->method('displayBothPrices')->willReturn(false);
        $catalogHelper = $this->createMock(CatalogHelper::class);
        // A 21% rate: including tax multiplies, excluding tax divides.
        $catalogHelper->method('getTaxPrice')->willReturnCallback(
            static fn($product, $price, $includingTax) => $includingTax ? $price * 1.21 : $price / 1.21
        );

        return new DisplayPrice(
            $priceCurrency,
            $taxHelper,
            $catalogHelper,
            $this->createMock(WeeeHelper::class),
            $this->createMock(StockConfigurationInterface::class)
        );
    }

    public function testExcludingTaxInBaseCurrencyLeavesTheAmounts(): void
    {
        $display = $this->displayPrice(false, false, 1.0);
        $product = $this->createMock(Product::class);
        $store = $this->createMock(StoreInterface::class);
        self::assertSame(10.0, $display->regular(10.0, $product, $store)->value);
        self::assertSame(8.0, $display->final(8.0, 10.0, $product, $store)->value);
        self::assertSame(10.0, $display->final(10.0, 10.0, $product, $store)->value);
        self::assertSame(0.0, $display->regular(10.0, $product, $store)->tax);
        self::assertFalse($display->taxIncluded($store));
    }

    public function testDisplayIncludingTaxConvertsThenTaxes(): void
    {
        $display = $this->displayPrice(false, true, 0.5);
        $product = $this->createMock(Product::class);
        $store = $this->createMock(StoreInterface::class);
        $regular = $display->regular(10.0, $product, $store);
        self::assertEqualsWithDelta(10.0 * 0.5 * 1.21, $regular->value, 1e-9);
        self::assertEqualsWithDelta(10.0 * 0.5 * 0.21, $regular->tax, 1e-9);
        // A discounted price is rounded after conversion, the regular price is not.
        self::assertEqualsWithDelta(round(7.777 * 0.5, 2) * 1.21, $display->final(7.777, 10.0, $product, $store)->value, 1e-9);
        self::assertTrue($display->taxIncluded($store));
    }

    public function testCatalogPricesIncludingTaxAlwaysCarryTax(): void
    {
        $display = $this->displayPrice(true, false, 1.0);
        $product = $this->createMock(Product::class);
        $store = $this->createMock(StoreInterface::class);
        $regular = $display->regular(10.0, $product, $store);
        self::assertEqualsWithDelta(12.1, $regular->value, 1e-9);
        self::assertEqualsWithDelta(12.1 - 10.0 / 1.21, $regular->tax, 1e-9);
    }
}
