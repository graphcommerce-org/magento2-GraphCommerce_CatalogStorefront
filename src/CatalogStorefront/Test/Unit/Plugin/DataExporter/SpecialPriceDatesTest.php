<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Plugin\DataExporter;

use GraphCommerce\CatalogStorefront\Plugin\DataExporter\SpecialPriceDates;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\ProductPriceDataExporter\Model\Provider\ProductPrice;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

class SpecialPriceDatesTest extends TestCase
{
    private const FROM = 77;
    private const TO = 78;

    public function testASpecialPriceCountsOnlyInsideItsWindowOfTheWebsitesDefaultStoreView(): void
    {
        $special = ['code' => 'special_price', 'price' => 75.0];
        $rule = ['code' => 'catalog_rule', 'price' => 90.0];
        $row = fn(int $id) => ['productId' => (string)$id, 'website_id' => '1', 'discounts' => [$special, $rule]];

        $output = $this->plugin([
            // 1 active, 2 expired, 3 not started, 4 expired by default but extended in the store view, 5 without dates
            ['entity_id' => 1, 'store_id' => 0, 'attribute_id' => self::FROM, 'value' => '2000-01-01 00:00:00'],
            ['entity_id' => 1, 'store_id' => 0, 'attribute_id' => self::TO, 'value' => '2099-12-31 00:00:00'],
            ['entity_id' => 2, 'store_id' => 0, 'attribute_id' => self::TO, 'value' => '2001-01-01 00:00:00'],
            ['entity_id' => 3, 'store_id' => 0, 'attribute_id' => self::FROM, 'value' => '2098-01-01 00:00:00'],
            ['entity_id' => 4, 'store_id' => 0, 'attribute_id' => self::TO, 'value' => '2001-01-01 00:00:00'],
            ['entity_id' => 4, 'store_id' => 2, 'attribute_id' => self::TO, 'value' => '2099-12-31 00:00:00'],
        ])->afterGet($this->createMock(ProductPrice::class), [
            'a' => $row(1), 'b' => $row(2), 'c' => $row(3), 'd' => $row(4), 'e' => $row(5),
        ]);

        self::assertSame([$special, $rule], $output['a']['discounts']);
        self::assertSame([$rule], $output['b']['discounts']);
        self::assertSame([$rule], $output['c']['discounts']);
        self::assertSame([$special, $rule], $output['d']['discounts']);
        self::assertSame([$special, $rule], $output['e']['discounts']);
    }

    public function testRowsWithoutASpecialPriceReadNoDates(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::never())->method('fetchAll');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $plugin = new SpecialPriceDates(
            $resource,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(MetadataPool::class),
            $this->createMock(EavConfig::class),
            $this->createMock(TimezoneInterface::class),
        );
        $rows = ['a' => ['productId' => '1', 'website_id' => '1', 'discounts' => [['code' => 'catalog_rule', 'price' => 9.0]]]];

        self::assertSame($rows, $plugin->afterGet($this->createMock(ProductPrice::class), $rows));
    }

    private function plugin(array $rows): SpecialPriceDates
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(2);
        $website = $this->createMock(Website::class);
        $website->method('getDefaultStore')->willReturn($store);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getWebsite')->with(1)->willReturn($website);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);

        $eav = $this->createMock(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function (string $entity, string $code) {
            $attribute = $this->createMock(AbstractAttribute::class);
            $attribute->method('getId')->willReturn($code === 'special_from_date' ? self::FROM : self::TO);

            return $attribute;
        });

        // Today is 2026-10-10 in the store view; core's interval includes both bound days.
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('isScopeDateInInterval')->willReturnCallback(
            static fn($scope, $from = null, $to = null) => ($from === null || substr($from, 0, 10) <= '2026-10-10')
                && ($to === null || substr($to, 0, 10) >= '2026-10-10')
        );

        return new SpecialPriceDates($resource, $stores, $pool, $eav, $timezone);
    }
}
