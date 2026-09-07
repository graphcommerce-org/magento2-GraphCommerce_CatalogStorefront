<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use PHPUnit\Framework\TestCase;

class ProductPriceTest extends TestCase
{
    private ProductPrice $price;

    protected function setUp(): void
    {
        $this->price = new ProductPrice();
    }

    public function testRowPrefersTheGroupAndFallsBackToTheAllGroupsRow(): void
    {
        $prices = [
            ['group' => 'all', 'regular' => 10.0],
            ['group' => '2', 'regular' => 8.0],
        ];
        self::assertSame(8.0, $this->price->row($prices, '2')['regular']);
        self::assertSame(10.0, $this->price->row($prices, '1')['regular']);
        self::assertNull($this->price->row([['group' => '2', 'regular' => 8.0]], '1'));
        self::assertNull($this->price->row([['group' => 'all']], '0'));
    }

    public function testIndexEntryByGroup(): void
    {
        $index = [['group' => '0', 'regular' => 10, 'final' => 9], ['group' => '3', 'regular' => 7, 'final' => 6.5]];
        self::assertSame(['regular' => 7.0, 'final' => 6.5, 'precision' => 2], $this->price->indexEntry($index, '3'));
        self::assertNull($this->price->indexEntry($index, '1'));
    }

    public function testFinalPriceTakesTheLowestDiscountOrSingleQuantityTier(): void
    {
        $row = [
            'regular' => 100.0,
            'discounts' => [['code' => 'special_price', 'price' => 80.0], ['code' => 'catalog_rule', 'percentage' => 10.0]],
            'tierPrices' => [['qty' => 1, 'price' => 75.0], ['qty' => 5, 'price' => 10.0]],
        ];
        self::assertSame(75.0, $this->price->finalPrice($row));
        self::assertSame(90.0, $this->price->finalPrice(['regular' => 100.0, 'discounts' => [['percentage' => 10.0]]]));
        self::assertSame(0.0, $this->price->finalPrice(['regular' => 10.0, 'discounts' => [['price' => -1.0]]]));
    }

    public function testFinalPrecisionFollowsTheWinningSource(): void
    {
        $price = new ProductPrice();
        self::assertSame(2, $price->finalPrecision(['regular' => 10.0]));
        self::assertSame(4, $price->finalPrecision(['regular' => 10.0, 'discounts' => [['code' => 'catalog_rule', 'percentage' => 20]]]));
        self::assertSame(2, $price->finalPrecision(['regular' => 10.0, 'discounts' => [['code' => 'special_price', 'price' => 9.0]]]));
        self::assertSame(2, $price->finalPrecision([
            'regular' => 10.0,
            'discounts' => [['code' => 'catalog_rule', 'price' => 9.0]],
            'tierPrices' => [['qty' => 1, 'price' => 7.0]],
        ]));
    }

    public function testBundlePayPercent(): void
    {
        self::assertNull($this->price->bundlePayPercent(['regular' => 10.0]));
        self::assertSame(80.0, $this->price->bundlePayPercent(['discounts' => [['code' => 'special_price', 'percentage' => 80.0]]]));
        self::assertSame(70.0, $this->price->bundlePayPercent([
            'discounts' => [['code' => 'special_price', 'percentage' => 80.0]],
            'tierPrices' => [['qty' => 1, 'percentage' => 30.0], ['qty' => 3, 'percentage' => 90.0]],
        ]));
    }
}
