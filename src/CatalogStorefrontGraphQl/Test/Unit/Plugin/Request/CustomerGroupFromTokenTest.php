<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Request\CustomerClaims;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request\CustomerGroupFromToken;
use Magento\Authorization\Model\UserContextInterface;
use Magento\CustomerGraphQl\Model\Context\AddCustomerGroupToContext;
use Magento\GraphQl\Model\Query\ContextParametersInterface;
use PHPUnit\Framework\TestCase;

class CustomerGroupFromTokenTest extends TestCase
{
    private function plugin(?array $claims): CustomerGroupFromToken
    {
        $customerClaims = $this->createMock(CustomerClaims::class);
        $customerClaims->method('get')->with(7, UserContextInterface::USER_TYPE_CUSTOMER)->willReturn($claims);

        return new CustomerGroupFromToken($customerClaims);
    }

    private function parameters(): ContextParametersInterface
    {
        $parameters = $this->createMock(ContextParametersInterface::class);
        $parameters->method('getUserId')->willReturn(7);
        $parameters->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_CUSTOMER);

        return $parameters;
    }

    public function testTheGroupComesFromTheClaim(): void
    {
        $parameters = $this->parameters();
        $parameters->expects(self::once())->method('addExtensionAttribute')->with('customer_group_id', 2);

        self::assertSame($parameters, $this->plugin(['uid' => 7, 'gid' => 2, 'is_customer' => true])->aroundExecute(
            $this->createMock(AddCustomerGroupToContext::class),
            static fn() => self::fail('the token answers'),
            $parameters
        ));
    }

    public function testUsersWithoutClaimsAndOtherWebsitesCustomersGoToCore(): void
    {
        $proceed = static fn(ContextParametersInterface $p) => $p;
        $none = $this->parameters();
        $none->expects(self::never())->method('addExtensionAttribute');
        $elsewhere = $this->parameters();
        $elsewhere->expects(self::never())->method('addExtensionAttribute');

        $this->plugin(null)->aroundExecute($this->createMock(AddCustomerGroupToContext::class), $proceed, $none);
        $this->plugin(['uid' => 7, 'gid' => 2, 'is_customer' => false])->aroundExecute($this->createMock(AddCustomerGroupToContext::class), $proceed, $elsewhere);
    }
}
