<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPrice\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontPrice\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\FixedProductTax;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use PHPUnit\Framework\TestCase;

class DisplayPriceTest extends TestCase
{
    private function displayPrice(bool $priceIncludesTax, bool $displayIncludingTax, float $rate, array $weee = [0.0, 0.0]): DisplayPrice
    {
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('convert')->willReturnCallback(static fn($amount) => $amount * $rate);
        $priceCurrency->method('convertAndRound')->willReturnCallback(
            static fn($amount, $scope = null, $currency = null, $precision = 2) => round($amount * $rate, $precision)
        );
        $taxHelper = $this->createMock(TaxHelper::class);
        $taxHelper->method('priceIncludesTax')->willReturn($priceIncludesTax);
        $taxHelper->method('displayPriceIncludingTax')->willReturn($displayIncludingTax);
        $taxHelper->method('displayBothPrices')->willReturn(false);
        $catalogHelper = $this->createMock(CatalogHelper::class);
        // A 21% rate: including tax multiplies, excluding tax divides.
        $catalogHelper->method('getTaxPrice')->willReturnCallback(
            static fn($product, $price, $includingTax) => $includingTax ? $price * 1.21 : $price / 1.21
        );

        $fixedProductTax = $this->createMock(FixedProductTax::class);
        $fixedProductTax->method('adjustments')->willReturn($weee);

        return new DisplayPrice(
            $priceCurrency,
            $taxHelper,
            $catalogHelper,
            $this->createMock(StockConfigurationInterface::class),
            $fixedProductTax
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
        // A discounted price is rounded after conversion to the decimals of its source, the regular price is not.
        self::assertEqualsWithDelta(round(7.777 * 0.5, 2) * 1.21, $display->final(7.777, 10.0, $product, $store, 2)->value, 1e-9);
        self::assertEqualsWithDelta(round(7.777 * 0.5, 4) * 1.21, $display->final(7.777, 10.0, $product, $store)->value, 1e-9);
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

    public function testFixedProductTaxesRaiseTheAmountAndTravelAsParts(): void
    {
        $display = $this->displayPrice(false, true, 1.0, [2.0, 0.42]);
        $product = $this->createMock(Product::class);
        $store = $this->createMock(StoreInterface::class);
        $rows = [['country' => 'US', 'region' => 0, 'website' => 0, 'value' => 2.0]];
        $regular = $display->regular(10.0, $product, $store, $rows);
        self::assertEqualsWithDelta(12.1 + 2.42, $regular->value, 1e-9);
        self::assertEqualsWithDelta(2.1, $regular->tax, 1e-9);
        self::assertSame(2.0, $regular->weee);
        self::assertSame(0.42, $regular->weeeTax);
        // Without rows the price carries no fixed product tax.
        self::assertSame(0.0, $display->regular(10.0, $product, $store)->weee);
    }
}
