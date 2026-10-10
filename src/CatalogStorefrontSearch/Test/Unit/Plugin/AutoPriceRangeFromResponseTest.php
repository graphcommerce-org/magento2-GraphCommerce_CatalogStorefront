<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontSearch\Model\Config;
use GraphCommerce\CatalogStorefrontSearch\Plugin\AutoPriceRangeFromResponse;
use GraphCommerce\CatalogStorefrontSearch\Plugin\LeanPriceQuery;
use Magento\Elasticsearch\SearchAdapter\Aggregation\Builder\Dynamic;
use Magento\Elasticsearch\SearchAdapter\QueryContainer;
use Magento\Framework\Search\Adapter\OptionsInterface;
use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\Dynamic\EntityStorage;
use Magento\Framework\Search\Dynamic\EntityStorageFactory;
use Magento\Framework\Search\Request\Aggregation\DynamicBucket;
use PHPUnit\Framework\TestCase;

class AutoPriceRangeFromResponseTest extends TestCase
{
    /**
     * The step core's Auto::getRange() finds for these prices: a histogram
     * query per step until one has two or more non-empty buckets.
     */
    private function coreRange(array $prices): float
    {
        $maxPrice = floor(max($prices));
        $index = 1;
        do {
            $range = pow(10, strlen((string)floor($maxPrice)) - $index);
            $buckets = count(array_unique(array_map(static fn (float $p) => floor($p / $range), $prices)));
            $index++;
        } while ($range > 10 && $buckets < 2);

        return (float)$range;
    }

    /** @return array{0: AutoPriceRangeFromResponse, 1: DataProviderInterface, 2: \ArrayObject} */
    private function plugin(bool $enabled = true, $categoryRange = null): array
    {
        $config = $this->createMock(Config::class);
        $config->method('priceRangesFromResponse')->willReturn($enabled);
        $options = $this->createMock(OptionsInterface::class);
        $options->method('get')->willReturn(['min_range_power' => 10]);
        $factory = $this->createMock(EntityStorageFactory::class);
        $factory->method('create')->willReturnCallback(fn (array $ids) => new EntityStorage($ids));

        $asked = new \ArrayObject();
        $dataProvider = $this->createMock(DataProviderInterface::class);
        $dataProvider->method('getRange')->willReturn($categoryRange);
        $dataProvider->expects($this->never())->method('getAggregations');
        $dataProvider->method('getAggregation')->willReturnCallback(function ($bucket, $dimensions, $range) use ($asked) {
            $asked[] = (float)$range;
            return [1 => 3, 2 => 1];
        });
        $dataProvider->method('prepareData')->willReturnCallback(static fn ($range, array $counts) => array_map(
            static fn (int $key, int $count) => ['from' => ($key - 1) * $range, 'to' => $key * $range, 'count' => $count],
            array_keys($counts),
            $counts
        ));

        return [new AutoPriceRangeFromResponse($config, $options, $factory), $dataProvider, $asked];
    }

    private function searchResponse(array $prices): array
    {
        return [
            'hits' => ['hits' => [['_id' => '1'], ['fields' => ['_id' => ['2']]]]],
            'aggregations' => ['price_bucket' => ['count' => count($prices), 'min' => min($prices), 'max' => max($prices)]],
        ];
    }

    private function bucket(string $method): DynamicBucket
    {
        return new DynamicBucket('price_bucket', 'price', $method);
    }

    public function testTheStepIsTheOneCoreFindsWithOneHistogramQuery(): void
    {
        mt_srand(73);
        $sets = [[0.5], [7.0, 9.99], [12.0, 19.0], [100.0, 199.99], [100.0, 200.0], [1999.0, 1000.0, 1500.0], [45.0, 45.0], [10.0, 99.0], [1.0, 1234567.0]];
        for ($i = 0; $i < 300; $i++) {
            $base = mt_rand(0, 6);
            $sets[] = array_map(static fn () => mt_rand(0, (int)(10 ** $base) * 100) / 100 + (10 ** mt_rand(0, $base)), range(1, mt_rand(1, 8)));
        }
        foreach ($sets as $prices) {
            [$plugin, $dataProvider, $asked] = $this->plugin();
            $proceed = fn () => self::fail('core ran');
            $data = $plugin->aroundBuild($this->createMock(Dynamic::class), $proceed, $this->bucket('auto'), [], $this->searchResponse($prices), $dataProvider);

            self::assertSame([$this->coreRange($prices)], $asked->getArrayCopy(), json_encode($prices));
            $name = array_key_first($data);
            self::assertSame($name, $data[$name]['value']);
        }
    }

    public function testACategoryStepIsUsedAsIs(): void
    {
        [$plugin, $dataProvider, $asked] = $this->plugin(true, 25);
        $plugin->aroundBuild($this->createMock(Dynamic::class), fn () => self::fail('core ran'), $this->bucket('auto'), [], $this->searchResponse([10.0, 990.0]), $dataProvider);

        self::assertSame([25.0], $asked->getArrayCopy());
    }

    public function testCoreRunsForAnotherModeOrWhenTheSettingIsOff(): void
    {
        foreach ([[true, 'manual'], [true, 'improved'], [false, 'auto']] as [$enabled, $method]) {
            [$plugin, $dataProvider] = $this->plugin($enabled);
            $result = $plugin->aroundBuild($this->createMock(Dynamic::class), fn () => ['core'], $this->bucket($method), [], $this->searchResponse([1.0, 500.0]), $dataProvider);
            self::assertSame(['core'], $result);
        }
    }

    public function testAPageWithoutProductsHasNoRanges(): void
    {
        [$plugin, $dataProvider, $asked] = $this->plugin();
        $result = $this->searchResponse([1.0, 500.0]);
        $result['hits']['hits'] = [];

        self::assertSame([], $plugin->aroundBuild($this->createMock(Dynamic::class), fn () => self::fail('core ran'), $this->bucket('auto'), [], $result, $dataProvider));
        self::assertCount(0, $asked);
    }

    public function testThePriceQueryAsksForAggregationsOnly(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('priceRangesFromResponse')->willReturnOnConsecutiveCalls(true, false);
        $plugin = new LeanPriceQuery($config);
        $container = $this->createMock(QueryContainer::class);
        $query = ['index' => 'magento2_product_1', 'body' => [
            'from' => 3456, 'size' => 48, 'sort' => [['entity_id' => ['order' => 'asc']]],
            'stored_fields' => '_none_', 'docvalue_fields' => ['_id', '_score'],
            'query' => ['bool' => ['must' => [['match' => ['name' => 'chair']]]]],
            'aggregations' => ['price_bucket' => ['extended_stats' => ['field' => 'price_0_1']]],
        ]];

        self::assertEquals(['index' => 'magento2_product_1', 'body' => [
            'query' => ['bool' => ['filter' => [['bool' => ['must' => [['match' => ['name' => 'chair']]]]]]]],
            'size' => 0, 'track_total_hits' => false,
        ]], $plugin->afterGetQuery($container, $query));
        $pit = ['body' => ['pit' => ['id' => 'x'], 'size' => 48]];
        self::assertSame($pit, $plugin->afterGetQuery($container, $pit));
        self::assertSame($query, $plugin->afterGetQuery($container, $query));
    }
}
