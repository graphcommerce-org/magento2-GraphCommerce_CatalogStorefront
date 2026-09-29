<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model;

/**
 * Builds and parses the engine query for configurable and grouped child price ranges.
 */
class ChildPriceRanges
{
    /**
     * Per parent, the minimum and maximum regular and final price of the
     * children's price index entries for the group, over the salable children
     * and over all enabled children, and the same bounds per child tax class,
     * since core taxes each child's amounts with the child's own class before
     * it picks the bounds.
     */
    public function build(string $parentField, array $parentIds, string $groupKey): array
    {
        $group = ['nested' => ['path' => 'priceIndex'], 'aggs' => ['group' => [
            'filter' => ['term' => ['priceIndex.group' => $groupKey]],
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

        return [
            'size' => 0,
            'query' => ['bool' => ['filter' => [
                ['terms' => [$parentField => $parentIds]],
                ['term' => ['status' => 'Enabled']],
            ]]],
            'aggs' => ['parents' => [
                'terms' => ['field' => $parentField, 'size' => count($parentIds), 'include' => $parentIds],
                'aggs' => [
                    'salable' => ['filter' => ['term' => ['stock.isSalable' => true]], 'aggs' => $prices],
                    'all' => ['filter' => ['match_all' => new \stdClass()], 'aggs' => $prices],
                ],
            ]],
        ];
    }

    /**
     * @return array<int, array<string, array|null>> per parent and mode: minimum regular, minimum
     *   final, maximum regular, maximum final, and the same four per child tax class id
     */
    public function parse(array $response): array
    {
        $ranges = [];
        foreach ($response['aggregations']['parents']['buckets'] ?? [] as $bucket) {
            foreach (['salable', 'all'] as $mode) {
                $stats = $bucket[$mode]['prices']['group'];
                if (!isset($stats['minFinal']['value'])) {
                    $ranges[(int)$bucket['key']][$mode] = null;
                    continue;
                }
                $byTaxClass = [];
                foreach ($bucket[$mode]['taxClasses']['buckets'] ?? [] as $class) {
                    if (!empty($class['fixedTaxes']['sum_other_doc_count'])) {
                        throw new \RuntimeException('Composite fixed product tax groups exceed the aggregation limit.');
                    }
                    if (!empty($class['fixedTaxes']['buckets'])) {
                        foreach ($class['fixedTaxes']['buckets'] as $taxes) {
                            $classStats = $taxes['prices']['group'];
                            if (isset($classStats['minFinal']['value'])) {
                                $byTaxClass[$class['key'] . ':' . $taxes['key']] = [
                                    (float)$classStats['minRegular']['value'],
                                    (float)$classStats['minFinal']['value'],
                                    (float)$classStats['maxRegular']['value'],
                                    (float)$classStats['maxFinal']['value'],
                                    'taxClassId' => (int)$class['key'],
                                    'fixedProductTaxes' => $taxes['document']['hits']['hits'][0]['_source']['fixedProductTaxes'] ?? [],
                                ];
                            }
                        }
                    }
                    $classStats = $class['plain']['prices']['group'];
                    if (isset($classStats['minFinal']['value'])) {
                        $byTaxClass[(int)$class['key']] = [
                            (float)$classStats['minRegular']['value'],
                            (float)$classStats['minFinal']['value'],
                            (float)$classStats['maxRegular']['value'],
                            (float)$classStats['maxFinal']['value'],
                        ];
                    }
                }
                $ranges[(int)$bucket['key']][$mode] = [
                    (float)$stats['minRegular']['value'],
                    (float)$stats['minFinal']['value'],
                    (float)$stats['maxRegular']['value'],
                    (float)$stats['maxFinal']['value'],
                    $byTaxClass,
                    (int)($stats['discounted']['doc_count'] ?? 0) > 0,
                ];
            }
        }

        return $ranges;
    }
}
