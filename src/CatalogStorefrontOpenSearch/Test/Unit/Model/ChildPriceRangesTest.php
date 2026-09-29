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
                'discounted' => ['filter' => ['script' => ['script' => [
                    'source' => "doc['priceIndex.final'].value < doc['priceIndex.regular'].value",
                    'lang' => 'painless',
                ]]]],
            ],
        ]]];
        $prices = ['prices' => $group, 'taxClasses' => [
            'terms' => ['field' => 'taxClassId', 'size' => 100, 'missing' => '0'],
            'aggs' => ['plain' => [
                'filter' => ['bool' => ['must_not' => [['exists' => ['field' => 'fixedProductTaxKey']]]]],
                'aggs' => ['prices' => $group],
            ], 'fixedTaxes' => [
                'terms' => ['field' => 'fixedProductTaxKey', 'size' => 1000],
                'aggs' => [
                    'prices' => $group,
                    'document' => ['top_hits' => ['size' => 1, '_source' => ['fixedProductTaxes']]],
                ],
            ]],
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
            'discounted' => ['doc_count' => $minFinal === null ? 0 : 1],
        ];
        $response = ['aggregations' => ['parents' => ['buckets' => [[
            'key' => '7',
            'salable' => [
                'prices' => ['group' => $stats(90.125)],
                'taxClasses' => ['buckets' => [
                    ['key' => '2', 'plain' => ['prices' => ['group' => $stats(90.125)]]],
                    ['key' => '4', 'plain' => ['prices' => ['group' => $stats(null, 10)]]],
                ]],
            ],
            'all' => [
                'prices' => ['group' => $stats(null)],
                'taxClasses' => ['buckets' => []],
            ],
        ]]]]];

        self::assertSame([
            7 => [
                'salable' => [100.0, 90.125, 120.0, 110.0, [2 => [100.0, 90.125, 120.0, 110.0]], true],
                'all' => null,
            ],
        ], (new ChildPriceRanges())->parse($response));
    }

    public function testFixedTaxesKeepSeparateBoundsWithinOneTaxClass(): void
    {
        $stats = ['minRegular' => ['value' => 10], 'minFinal' => ['value' => 8], 'maxRegular' => ['value' => 20], 'maxFinal' => ['value' => 18]];
        $taxes = [['country' => 'US', 'value' => 5]];
        $mode = ['prices' => ['group' => $stats], 'taxClasses' => ['buckets' => [[
            'key' => 2,
            'plain' => ['prices' => ['group' => $stats]],
            'fixedTaxes' => ['buckets' => [
                ['key' => 'tax-key', 'prices' => ['group' => $stats], 'document' => ['hits' => ['hits' => [['_source' => ['fixedProductTaxes' => $taxes]]]]]],
            ]],
        ]]]];
        $ranges = (new ChildPriceRanges())->parse(['aggregations' => ['parents' => ['buckets' => [[
            'key' => 7, 'salable' => $mode, 'all' => $mode,
        ]]]]]);

        self::assertSame([10.0, 8.0, 20.0, 18.0], $ranges[7]['salable'][4][2]);
        self::assertSame($taxes, $ranges[7]['salable'][4]['2:tax-key']['fixedProductTaxes']);
        self::assertSame(2, $ranges[7]['salable'][4]['2:tax-key']['taxClassId']);
        self::assertSame([10.0, 8.0, 20.0, 18.0], array_slice($ranges[7]['salable'][4]['2:tax-key'], 0, 4));
    }
}
