<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontSearch\Model\Config;
use GraphCommerce\CatalogStorefrontSearch\Plugin\ResultWindowPageSize;
use GraphCommerce\CatalogStorefrontSearch\Plugin\ResultWindowSetting;
use Magento\Elasticsearch\Model\Adapter\Index\Builder;
use Magento\Search\Model\Search\PageSizeProvider;
use PHPUnit\Framework\TestCase;

class ResultWindowTest extends TestCase
{
    public function testTheWindowIsTheIndexSettingAndThePageSize(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('resultWindow')->willReturn(500000);

        $settings = (new ResultWindowSetting($config))->afterBuild($this->createMock(Builder::class), ['analysis' => []]);
        self::assertSame(['analysis' => [], 'max_result_window' => 500000], $settings);
        self::assertSame(500000, (new ResultWindowPageSize($config))->afterGetMaxPageSize($this->createMock(PageSizeProvider::class), 10000));
    }

    public function testAnEmptyWindowLeavesCoreAlone(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('resultWindow')->willReturn(0);

        self::assertSame(['analysis' => []], (new ResultWindowSetting($config))->afterBuild($this->createMock(Builder::class), ['analysis' => []]));
        self::assertSame(10000, (new ResultWindowPageSize($config))->afterGetMaxPageSize($this->createMock(PageSizeProvider::class), 10000));
    }
}
