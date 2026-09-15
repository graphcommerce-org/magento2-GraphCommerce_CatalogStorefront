<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefront\Model\DataExporter\Provider\TaxClass;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class TaxClassTest extends TestCase
{
    public function testTheStoreViewValueWinsAndAProductWithoutAValueIsLeftOut(): void
    {
        $where = [];
        $provider = $this->provider([
            ['entity_id' => '1', 'value' => '2'],
            ['entity_id' => '1', 'value' => '4'],
            ['entity_id' => '2', 'value' => null],
        ], $where);

        $output = $provider->get([
            ['productId' => '1', 'storeViewCode' => 'default'],
            ['productId' => '2', 'storeViewCode' => 'default'],
            ['productId' => '3', 'storeViewCode' => 'default'],
        ]);

        self::assertSame([
            'default_1' => ['productId' => '1', 'storeViewCode' => 'default', 'taxClassId' => 4],
        ], $output);
        self::assertContains(['value.store_id IN (?)', [0, 1]], $where);
    }

    private function provider(array $rows, array &$where): TaxClass
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            static function (string $condition, $value = null) use ($select, &$where): Select {
                $where[] = [$condition, $value];

                return $select;
            }
        );
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteInto')->willReturnCallback(
            static fn(string $text, $value) => str_replace('?', (string)$value, $text)
        );
        $connection->method('fetchAll')->willReturn($rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $pool = $this->createMock(MetadataPool::class);
        $pool->method('getMetadata')->willReturn($metadata);

        $attribute = $this->createMock(AbstractAttribute::class);
        $attribute->method('getId')->willReturn(133);
        $eav = $this->createMock(EavConfig::class);
        $eav->method('getAttribute')->willReturn($attribute);

        return new TaxClass($resource, $stores, $pool, $eav);
    }
}
