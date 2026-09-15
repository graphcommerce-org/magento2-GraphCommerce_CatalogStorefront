<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;

/**
 * Adds to a prices feed row the final price its discounts and tier prices
 * make of the regular price, and the decimals core rounds that source to, so
 * the row carries the price index entry of its own customer group. A deleted
 * row carries no price and gets neither.
 */
class FinalPrice
{
    public function __construct(
        private readonly ProductPrice $productPrice,
    ) {
    }

    public function get(array $values): array
    {
        $output = [];
        foreach ($values as $value) {
            if (!isset($value['regular'])) {
                continue;
            }
            $output[$value['productPriceId']] = [
                'productPriceId' => $value['productPriceId'],
                'final' => $this->productPrice->finalPrice($value),
                'precision' => $this->productPrice->finalPrecision($value),
            ];
        }

        return $output;
    }
}
