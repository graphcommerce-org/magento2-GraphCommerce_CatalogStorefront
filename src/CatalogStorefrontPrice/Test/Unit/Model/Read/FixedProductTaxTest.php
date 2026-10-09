<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPrice\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontPrice\Model\Read\FixedProductTax;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session;
use Magento\Framework\DataObject;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Calculation;
use Magento\Weee\Helper\Data as WeeeHelper;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Weee\Model\Tax as WeeeTax;
use PHPUnit\Framework\TestCase;

class FixedProductTaxTest extends TestCase
{
    /** Michigan rows of two websites next to a Texas row and a row without an amount. */
    private const ROWS = [
        ['label' => 'Eco', 'country' => 'US', 'region' => 0, 'website' => 0, 'value' => 5.0],
        ['label' => 'Eco', 'country' => 'US', 'region' => 33, 'website' => 1, 'value' => 7.5],
        ['label' => 'Eco', 'country' => 'US', 'region' => 43, 'website' => 1, 'value' => 9.0],
        ['label' => 'Eco', 'country' => 'US', 'region' => 33, 'website' => 2, 'value' => 8.0],
        ['label' => 'Eco', 'country' => 'US', 'region' => 0, 'website' => 1, 'value' => 0.0],
    ];

    private function fixedProductTax(bool $priceIncludesTax, bool $taxable, int $listType, array $appliedRates = []): FixedProductTax
    {
        $calculation = $this->createMock(Calculation::class);
        $calculation->method('getRateRequest')->willReturn(new DataObject(['country_id' => 'US', 'region_id' => 33]));
        $calculation->method('getDefaultRateRequest')->willReturn(new DataObject(['country_id' => 'US', 'region_id' => 33]));
        // The default rate is 6, the destination's 8.25.
        $calculation->method('getRate')->willReturnOnConsecutiveCalls(6.0, 8.25);
        $calculation->method('getAppliedRates')->willReturn($appliedRates);
        $weeeHelper = $this->createMock(WeeeHelper::class);
        $weeeHelper->method('isEnabled')->willReturn(true);
        $weeeHelper->method('isTaxable')->willReturn($taxable);
        $weeeHelper->method('getListPriceDisplayType')->willReturn($listType);
        $taxHelper = $this->createMock(TaxHelper::class);
        $taxHelper->method('priceIncludesTax')->willReturn($priceIncludesTax);
        $taxHelper->method('displayPriceExcludingTax')->willReturn(false);
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('convert')->willReturnCallback(static fn($amount) => $amount * 0.5);

        return new FixedProductTax($calculation, $weeeHelper, $this->createMock(MetadataDocumentStorageInterface::class), $taxHelper, $priceCurrency, $this->createMock(Session::class));
    }

    private function store(): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);

        return $store;
    }

    public function testPicksTheRowsOfTheDestinationInCoresOrder(): void
    {
        $attributes = $this->fixedProductTax(false, false, WeeeTax::DISPLAY_EXCL)
            ->attributes(self::ROWS, $this->createMock(Product::class), $this->store(), false);

        // The region row before the all-regions row; the other region, website and empty rows are out.
        self::assertSame([7.5, 5.0], array_column($attributes, 'amount'));
        self::assertSame([0.0, 0.0], array_column($attributes, 'taxAmount'));
        self::assertSame(['Eco', 'Eco'], array_column($attributes, 'label'));
    }

    public function testTaxesAtTheDestinationRateOnPricesExcludingTax(): void
    {
        $attributes = $this->fixedProductTax(false, true, WeeeTax::DISPLAY_INCL)
            ->attributes(self::ROWS, $this->createMock(Product::class), $this->store(), true);

        self::assertEqualsWithDelta(7.5 * 8.25 / 100, $attributes[0]['taxAmount'], 1e-9);
        self::assertSame(7.5, $attributes[0]['amountExclTax']);
    }

    public function testSumsEveryAppliedRateOnPricesExcludingTax(): void
    {
        $attributes = $this->fixedProductTax(false, true, WeeeTax::DISPLAY_INCL, [['percent' => 5.0], ['percent' => 3.25]])
            ->attributes(self::ROWS, $this->createMock(Product::class), $this->store(), true);

        self::assertEqualsWithDelta(7.5 * 0.05 + 7.5 * 0.0325, $attributes[0]['taxAmount'], 1e-9);
    }

    public function testMovesTheAmountBetweenRatesOnPricesIncludingTax(): void
    {
        $attributes = $this->fixedProductTax(true, true, WeeeTax::DISPLAY_INCL)
            ->attributes(self::ROWS, $this->createMock(Product::class), $this->store(), true);

        $inclTax = 7.5 / 106 * 108.25;
        $tax = $inclTax - $inclTax / 108.25 * 100;
        self::assertEqualsWithDelta($tax, $attributes[0]['taxAmount'], 1e-9);
        self::assertEqualsWithDelta($inclTax - $tax, $attributes[0]['amountExclTax'], 1e-9);
        self::assertSame(7.5, $attributes[0]['amount']);
    }

    public function testAdjustmentsFollowTheDisplayTypeAndConvert(): void
    {
        $product = $this->createMock(Product::class);
        [$weee, $weeeTax] = $this->fixedProductTax(false, true, WeeeTax::DISPLAY_INCL_DESCR)->adjustments(self::ROWS, $product, $this->store());
        self::assertEqualsWithDelta(12.5 * 0.5, $weee, 1e-9);
        self::assertEqualsWithDelta(12.5 * 8.25 / 100 * 0.5, $weeeTax, 1e-9);

        self::assertSame([0.0, 0.0], $this->fixedProductTax(false, true, WeeeTax::DISPLAY_EXCL)->adjustments(self::ROWS, $product, $this->store()));
        self::assertSame([0.0, 0.0], $this->fixedProductTax(false, true, WeeeTax::DISPLAY_INCL)->adjustments([], $product, $this->store()));
    }

    public function testCompositeParentAdjustments(): void
    {
        foreach (['configurable' => [0, 0.0], 'bundle' => [0, 0.0], 'fixed' => [1, 6.25]] as $type => [$priceType, $expected]) {
            $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
            $product->setTypeId($type === 'fixed' ? 'bundle' : $type)->setPriceType($priceType);
            [$weee, $tax] = $this->fixedProductTax(false, true, WeeeTax::DISPLAY_INCL_DESCR)
                ->adjustments(self::ROWS, $product, $this->store());

            self::assertSame($expected, $weee);
            self::assertEqualsWithDelta(12.5 * 0.0825 * 0.5, $tax, 1e-9);
        }
    }
}
