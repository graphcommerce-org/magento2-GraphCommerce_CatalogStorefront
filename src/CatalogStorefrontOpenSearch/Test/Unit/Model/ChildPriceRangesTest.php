<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontOpenSearch\Model\ChildPriceRanges;
use PHPUnit\Framework\TestCase;

class ChildPriceRangesTest extends TestCase
{
    public function testBuildsTheExistingGroupEnabledStockAndTaxClassAggregation(): void
    {
        $group = ['nested' => ['path' => 'priceIndex'], 'aggs' => ['group' => [
            'filter' => ['term' => ['priceIndex.group' => '2']],
            'aggs' => [
                'minRegular' => ['min' => ['field' => 'priceIndex.regular']],
                'minFinal' => ['min' => ['field' => 'priceIndex.final']],
                'maxRegular' => ['max' => ['field' => 'priceIndex.regular']],
                'maxFinal' => ['max' => ['field' => 'priceIndex.final']],
            ],
        ]]];
        $prices = ['prices' => $group, 'taxClasses' => [
            'terms' => ['field' => 'taxClassId', 'size' => 100, 'missing' => '0'],
            'aggs' => ['prices' => $group],
        ]];
        $expected = [
            'size' => 0,
            'query' => ['bool' => ['filter' => [
                ['terms' => ['parentIds' => ['7', '9']]],
                ['term' => ['status' => 'Enabled']],
            ]]],
            'aggs' => ['parents' => [
                'terms' => ['field' => 'parentIds', 'size' => 2, 'include' => ['7', '9']],
                'aggs' => [
                    'salable' => ['filter' => ['term' => ['stock.isSalable' => true]], 'aggs' => $prices],
                    'all' => ['filter' => ['match_all' => new \stdClass()], 'aggs' => $prices],
                ],
            ]],
        ];

        $actual = (new ChildPriceRanges())->build('parentIds', ['7', '9'], '2');

        self::assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($actual, JSON_THROW_ON_ERROR));
    }

    public function testParsesExistingRangesAndKeepsAMissingFinalAsNull(): void
    {
        $stats = static fn(?float $minFinal, float $offset = 0): array => [
            'minRegular' => ['value' => 100 + $offset],
            'minFinal' => ['value' => $minFinal],
            'maxRegular' => ['value' => 120 + $offset],
            'maxFinal' => ['value' => 110 + $offset],
        ];
        $response = ['aggregations' => ['parents' => ['buckets' => [[
            'key' => '7',
            'salable' => [
                'prices' => ['group' => $stats(90.125)],
                'taxClasses' => ['buckets' => [
                    ['key' => '2', 'prices' => ['group' => $stats(90.125)]],
                    ['key' => '4', 'prices' => ['group' => $stats(null, 10)]],
                ]],
            ],
            'all' => [
                'prices' => ['group' => $stats(null)],
                'taxClasses' => ['buckets' => []],
            ],
        ]]]]];

        self::assertSame([
            7 => [
                'salable' => [100.0, 90.125, 120.0, 110.0, [2 => [100.0, 90.125, 120.0, 110.0]]],
                'all' => null,
            ],
        ], (new ChildPriceRanges())->parse($response));
    }
}
