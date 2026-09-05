<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Tax;

use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Tax\CustomerAddressColumns;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Tax\Model\Calculation;
use PHPUnit\Framework\TestCase;

class CustomerAddressColumnsTest extends TestCase
{
    private function plugin(string $basedOn, array|false $row): CustomerAddressColumns
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($row);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($basedOn);
        $session = $this->createMock(Session::class);
        $session->method('getCustomerGroupId')->willReturn(2);
        $group = $this->createMock(GroupInterface::class);
        $group->method('getTaxClassId')->willReturn(3);
        $groups = $this->createMock(GroupRepositoryInterface::class);
        $groups->method('getById')->with(2)->willReturn($group);

        return new CustomerAddressColumns($resource, $scopeConfig, $session, $groups);
    }

    public function testTheShippingAddressColumnsAndTheSessionGroupReplaceTheCustomerLoad(): void
    {
        $row = ['country_id' => 'NL', 'region_id' => null, 'postcode' => '1011AB'];
        $seen = null;
        $proceed = static function (...$args) use (&$seen): DataObject {
            $seen = $args;

            return new DataObject();
        };

        $this->plugin('shipping', $row)->aroundGetRateRequest($this->createMock(Calculation::class), $proceed, null, null, null, 1, 7);

        self::assertSame($row, $seen[0]->getData());
        self::assertNull($seen[1]);
        self::assertSame([3, 1, null], array_slice($seen, 2));
    }

    public function testACustomerWithoutADefaultAddressGetsTheStoreDefault(): void
    {
        $seen = null;
        $proceed = static function (...$args) use (&$seen): DataObject {
            $seen = $args;

            return new DataObject();
        };

        $this->plugin('billing', false)->aroundGetRateRequest($this->createMock(Calculation::class), $proceed, null, null, 5, 1, 7);

        self::assertSame([null, null, 5, 1, null], $seen);
    }

    public function testGuestsAndExplicitAddressesGoToCore(): void
    {
        $seen = [];
        $proceed = static function (...$args) use (&$seen): DataObject {
            $seen[] = $args;

            return new DataObject();
        };
        $plugin = $this->plugin('shipping', ['country_id' => 'NL', 'region_id' => null, 'postcode' => '1011AB']);
        $cart = new DataObject(['country_id' => 'BE']);

        $plugin->aroundGetRateRequest($this->createMock(Calculation::class), $proceed, null, null, null, 1, null);
        $plugin->aroundGetRateRequest($this->createMock(Calculation::class), $proceed, $cart, null, null, 1, 7);

        self::assertSame([[null, null, null, 1, null], [$cart, null, null, 1, 7]], $seen);
    }
}
