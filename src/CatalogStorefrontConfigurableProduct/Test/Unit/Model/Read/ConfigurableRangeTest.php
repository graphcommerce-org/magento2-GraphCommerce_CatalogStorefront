<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\Amount;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read\ConfigurableRange;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\DisplayPrice;
use Magento\Catalog\Model\Product;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class ConfigurableRangeTest extends TestCase
{
    public function testFixedTaxChangesTheCheapestVariant(): void
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $product->setId(7)->setTypeId('configurable');
        $display = $this->createMock(DisplayPrice::class);
        $display->method('forTaxClass')->willReturnCallback(static fn(Product $parent) => clone $parent);
        $display->method('regular')->willReturnCallback(static function (float $base, Product $child, $store, array $taxes): Amount {
            self::assertSame('simple', $child->getTypeId());
            return new Amount($base + array_sum(array_column($taxes, 'value')));
        });
        $display->method('final')->willReturnCallback(static fn(float $base, float $regular, Product $child, $store, int $precision, array $taxes): Amount =>
            new Amount($base + array_sum(array_column($taxes, 'value'))));
        $context = new DocumentContext($this->createMock(StoreInterface::class), '0', [
            'configurable' => [7 => ['salable' => [8.0, 7.0, 10.0, 9.0, [
                2 => [10.0, 9.0, 10.0, 9.0],
                '2:eco' => [8.0, 7.0, 8.0, 7.0, 'taxClassId' => 2, 'fixedProductTaxes' => [['value' => 5.0]]],
            ]]]],
        ]);

        $range = (new ConfigurableRange($display))->range($product, [], $context, false);

        self::assertSame([10.0, 9.0, 13.0, 12.0], array_map(static fn(Amount $amount) => $amount->value, $range));
    }
}
