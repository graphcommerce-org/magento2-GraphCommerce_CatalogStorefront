<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProduct\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontBundleProduct\Model\Read\BundlePriceRange;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class BundlePriceRangeTest extends TestCase
{
    private function context(array $priceData): DocumentContext
    {
        return new DocumentContext($this->createMock(StoreInterface::class), '0', $priceData);
    }

    private function selection(string $sku, float $regular, float $final, bool $salable = true): array
    {
        return ['sku' => $sku, 'stock' => ['isSalable' => $salable], 'priceIndex' => [['group' => '0', 'regular' => $regular, 'final' => $final]]];
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
        $range = (new BundlePriceRange(new ProductPrice()))->range(1, $document, $this->context(['bundle' => [1 => [
            'a' => $this->selection('a', 10.0, 9.0),
            'b' => $this->selection('b', 4.0, 4.0),
            'c' => $this->selection('c', 5.0, 5.0),
            'd' => $this->selection('d', 6.0, 3.0, false),
        ]]]), true);

        // Minimum: the cheapest required selection (b, 2 x 4). Maximum: the dearest required (a) plus every checkbox selection.
        self::assertSame([8.0, 8.0, 10.0 + 5.0 + 6.0, 9.0 + 5.0 + 3.0], $range);
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
        $range = (new BundlePriceRange(new ProductPrice()))->range(1, $document, $this->context(['bundle' => [1 => [
            'a' => $this->selection('a', 0.0, 0.0),
            'b' => $this->selection('b', 0.0, 0.0),
        ]]]), false);

        self::assertSame([110.0, 55.0, 120.0, 60.0], $range);
    }

    public function testFixedBundleWithCustomizableOptionsIsNotAnswered(): void
    {
        $document = [
            'prices' => [['group' => '0', 'regular' => 100.0, 'type' => 'BUNDLE_FIXED']],
            'optionsV2' => [['type' => 'bundle', 'values' => []], ['type' => 'custom_option', 'values' => []]],
        ];
        self::assertNull((new BundlePriceRange(new ProductPrice()))->range(1, $document, $this->context([]), false));
    }
}
