<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Request\CustomerClaims;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request\CustomerSessionAfterDispatch;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use Magento\CustomerGraphQl\Model\Context\AddUserInfoToContext;
use Magento\Framework\App\ResponseInterface;
use Magento\GraphQl\Controller\GraphQl;
use PHPUnit\Framework\TestCase;

class CustomerSessionAfterDispatchTest extends TestCase
{
    private function plugin(?array $claims, Session $session, ?CustomerInterface $loaded): CustomerSessionAfterDispatch
    {
        $customerClaims = $this->createMock(CustomerClaims::class);
        $customerClaims->method('get')->with(7, UserContextInterface::USER_TYPE_CUSTOMER)->willReturn($claims);
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUserId')->willReturn(7);
        $userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_CUSTOMER);
        $core = $this->createMock(AddUserInfoToContext::class);
        $core->expects($loaded === null ? self::never() : self::once())->method('getLoggedInCustomerData')->willReturn($loaded);

        return new CustomerSessionAfterDispatch($customerClaims, $userContext, $session, $core);
    }

    public function testTheClaimsSetTheSessionWithoutALoad(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('setCustomerId')->with(7);
        $session->expects(self::once())->method('setCustomerGroupId')->with(2);
        $response = $this->createMock(ResponseInterface::class);

        self::assertSame($response, $this->plugin(['uid' => 7, 'gid' => 2, 'is_customer' => true], $session, null)
            ->afterDispatch($this->createMock(GraphQl::class), $response));
    }

    public function testAnotherWebsitesCustomerClearsTheSession(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('setCustomerId')->with(null);
        $session->expects(self::once())->method('setCustomerGroupId')->with(null);

        $this->plugin(['uid' => 7, 'gid' => 2, 'is_customer' => false], $session, null)
            ->afterDispatch($this->createMock(GraphQl::class), $this->createMock(ResponseInterface::class));
    }

    public function testAUserWithoutClaimsTakesCoresPath(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(7);
        $customer->method('getGroupId')->willReturn(3);
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('setCustomerId')->with(7);
        $session->expects(self::once())->method('setCustomerGroupId')->with(3);

        $this->plugin(null, $session, $customer)->afterDispatch($this->createMock(GraphQl::class), $this->createMock(ResponseInterface::class));
    }
}
