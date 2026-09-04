<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProduct\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontApi\Read\Amount;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontBundleProduct\Model\Read\BundlePriceRange;
use Magento\Catalog\Model\Product;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class BundlePriceRangeTest extends TestCase
{
    /**
     * A display price that adds 10% tax to a product of tax class 2 and none otherwise.
     */
    private function range(): BundlePriceRange
    {
        $display = $this->createMock(DisplayPrice::class);
        $display->method('forTaxClass')->willReturnCallback(function (Product $product, ?int $taxClassId): Product {
            $copy = $this->createMock(Product::class);
            $copy->method('getData')->with('tax_class_id')->willReturn($taxClassId);

            return $copy;
        });
        $display->method('amount')->willReturnCallback(static function (float $base, bool $discounted, Product $product): Amount {
            $tax = (int)$product->getData('tax_class_id') === 2 ? round($base * 0.1, 2) : 0.0;

            return new Amount($base + $tax, $tax);
        });

        return new BundlePriceRange(new ProductPrice(), $display);
    }

    private function product(int $id, ?int $taxClassId): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getData')->with('tax_class_id')->willReturn($taxClassId);

        return $product;
    }

    /**
     * @return float[] the values of the four amounts
     */
    private function values(?array $range): ?array
    {
        return $range === null ? null : array_map(static fn(Amount $amount) => $amount->value, $range);
    }

    private function context(array $priceData): DocumentContext
    {
        return new DocumentContext($this->createMock(StoreInterface::class), '0', $priceData);
    }

    private function selection(string $sku, float $regular, float $final, bool $salable = true, ?int $taxClassId = null): array
    {
        return [
            'sku' => $sku,
            'stock' => ['isSalable' => $salable],
            'priceIndex' => [['group' => '0', 'regular' => $regular, 'final' => $final]],
            'taxClassId' => $taxClassId,
        ];
    }

    public function testDynamicBundleSumsRequiredOptionsForTheMinimumAndEveryOptionForTheMaximum(): void
    {
        $document = [
            'stock' => ['isSalable' => true],
            'prices' => [['group' => '0', 'regular' => 0.0, 'type' => 'BUNDLE_DYNAMIC']],
            'optionsV2' => [
                ['type' => 'bundle', 'required' => true, 'renderType' => 'radio', 'values' => [['sku' => 'a', 'qty' => 1], ['sku' => 'b', 'qty' => 2]]],
                ['type' => 'bundle', 'required' => false, 'renderType' => 'checkbox', 'values' => [['sku' => 'c', 'qty' => 1], ['sku' => 'd', 'qty' => 1]]],
            ],
        ];
        $range = $this->range()->range($this->product(1, 2), $document, $this->context(['bundle' => [1 => [
            'a' => $this->selection('a', 10.0, 9.0, true, 2),
            'b' => $this->selection('b', 4.0, 4.0),
            'c' => $this->selection('c', 5.0, 5.0),
            'd' => $this->selection('d', 6.0, 3.0, false),
        ]]]), true);

        // Minimum: the cheapest required selection (b, 2 x 4, no tax). Maximum: the dearest required (a, taxed
        // with its own class) plus every checkbox selection.
        self::assertSame([8.0, 8.0, 11.0 + 5.0 + 6.0, 9.9 + 5.0 + 3.0], $this->values($range));
        self::assertSame(1.0, $range[2]->tax);
    }

    public function testFixedBundleAppliesPercentSelectionsAndTheSpecialPricePercent(): void
    {
        $document = [
            'stock' => ['isSalable' => true],
            'prices' => [['group' => '0', 'regular' => 100.0, 'type' => 'BUNDLE_FIXED', 'discounts' => [['code' => 'special_price', 'percentage' => 50.0]]]],
            'optionsV2' => [
                ['type' => 'bundle', 'required' => true, 'renderType' => 'select', 'values' => [
                    ['sku' => 'a', 'qty' => 1, 'price' => 10.0, 'priceType' => 'fixed'],
                    ['sku' => 'b', 'qty' => 1, 'price' => 20.0, 'priceType' => 'percent'],
                ]],
            ],
        ];
        $range = $this->range()->range($this->product(1, 2), $document, $this->context(['bundle' => [1 => [
            'a' => $this->selection('a', 0.0, 0.0),
            'b' => $this->selection('b', 0.0, 0.0),
        ]]]), false);

        // The fixed total is taxed as a whole with the bundle's class.
        self::assertSame([121.0, 60.5, 132.0, 66.0], $this->values($range));
    }

    public function testFixedBundleWithCustomizableOptionsIsNotAnswered(): void
    {
        $document = [
            'prices' => [['group' => '0', 'regular' => 100.0, 'type' => 'BUNDLE_FIXED']],
            'optionsV2' => [['type' => 'bundle', 'values' => []], ['type' => 'custom_option', 'values' => []]],
        ];
        self::assertNull($this->range()->range($this->product(1, 2), $document, $this->context([]), false));
    }
}
