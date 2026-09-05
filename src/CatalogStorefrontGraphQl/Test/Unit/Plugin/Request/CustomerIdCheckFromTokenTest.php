<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Request\CustomerClaims;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request\CustomerIdCheckFromToken;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Session;
use PHPUnit\Framework\TestCase;

class CustomerIdCheckFromTokenTest extends TestCase
{
    public function testTheTokensCustomerNeedsNoLoadAndOthersGoToCore(): void
    {
        $claims = $this->createMock(CustomerClaims::class);
        $claims->method('get')->willReturnCallback(
            static fn(int $id, int $type): ?array => $id === 7 && $type === UserContextInterface::USER_TYPE_CUSTOMER
                ? ['uid' => 7, 'gid' => 2, 'is_customer' => true]
                : null
        );
        $plugin = new CustomerIdCheckFromToken($claims);
        $session = $this->createMock(Session::class);
        $asked = [];
        $proceed = static function ($id) use (&$asked): bool {
            $asked[] = $id;

            return $id === 8;
        };

        self::assertTrue($plugin->aroundCheckCustomerId($session, $proceed, 7));
        self::assertTrue($plugin->aroundCheckCustomerId($session, $proceed, 8));
        self::assertFalse($plugin->aroundCheckCustomerId($session, $proceed, 9));
        self::assertSame([8, 9], $asked);
    }
}
