<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefront\Model\DataExporter\Provider\FinalPrice;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use PHPUnit\Framework\TestCase;

class FinalPriceTest extends TestCase
{
    public function testTheBestDiscountSetsTheFinalPriceAndItsSourceTheDecimals(): void
    {
        $output = (new FinalPrice(new ProductPrice()))->get([
            ['productPriceId' => 'a', 'regular' => 10.0],
            ['productPriceId' => 'b', 'regular' => 10.0, 'discounts' => [['code' => 'special_price', 'price' => 8.0]]],
            ['productPriceId' => 'c', 'regular' => 10.0, 'discounts' => [['code' => 'catalog_rule', 'price' => 9.0]]],
            ['productPriceId' => 'd', 'sku' => 'A', 'deleted' => true],
        ]);

        self::assertSame([
            'a' => ['productPriceId' => 'a', 'final' => 10.0, 'precision' => 2],
            'b' => ['productPriceId' => 'b', 'final' => 8.0, 'precision' => 2],
            'c' => ['productPriceId' => 'c', 'final' => 9.0, 'precision' => 4],
        ], $output);
    }
}
