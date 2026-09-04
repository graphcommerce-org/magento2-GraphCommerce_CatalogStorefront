<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Plugin\Layer\SingleRangeFromResponse;
use Magento\Elasticsearch\SearchAdapter\Aggregation\Builder\Dynamic;
use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\Request\Aggregation\DynamicBucket;
use PHPUnit\Framework\TestCase;

class SingleRangeFromResponseTest extends TestCase
{
    public function testServesTheSingleRangeFromTheResponseStats(): void
    {
        $plugin = new SingleRangeFromResponse();
        $subject = $this->createMock(Dynamic::class);
        $dataProvider = $this->createMock(DataProviderInterface::class);
        $bucket = $this->createMock(DynamicBucket::class);
        $bucket->method('getName')->willReturn('price_bucket');
        $bucket->method('getMethod')->willReturn('single');
        $response = ['aggregations' => ['price_bucket' => ['count' => 4, 'min' => 10, 'max' => 40.5, 'avg' => 20]]];
        $proceed = static fn() => self::fail('the response answers');

        self::assertSame(
            ['10_40.5' => ['from' => 10.0, 'to' => 40.5, 'count' => 4, 'value' => '10_40.5']],
            $plugin->aroundBuild($subject, $proceed, $bucket, [], $response, $dataProvider)
        );
    }

    public function testOtherModesAndMissingStatsProceed(): void
    {
        $plugin = new SingleRangeFromResponse();
        $subject = $this->createMock(Dynamic::class);
        $dataProvider = $this->createMock(DataProviderInterface::class);
        $proceed = static fn() => ['core' => []];

        $auto = $this->createMock(DynamicBucket::class);
        $auto->method('getName')->willReturn('price_bucket');
        $auto->method('getMethod')->willReturn('auto');
        $single = $this->createMock(DynamicBucket::class);
        $single->method('getName')->willReturn('price_bucket');
        $single->method('getMethod')->willReturn('single');

        self::assertSame(['core' => []], $plugin->aroundBuild($subject, $proceed, $auto, [], ['aggregations' => ['price_bucket' => ['count' => 1]]], $dataProvider));
        self::assertSame(['core' => []], $plugin->aroundBuild($subject, $proceed, $single, [], ['aggregations' => []], $dataProvider));
    }
}
