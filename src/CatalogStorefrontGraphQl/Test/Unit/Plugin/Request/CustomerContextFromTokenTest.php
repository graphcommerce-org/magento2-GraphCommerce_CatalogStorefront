<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Request\CustomerClaims;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request\CustomerContextFromToken;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Context;
use Magento\Customer\Model\Session;
use Magento\CustomerGraphQl\Model\Context\AddUserInfoToContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\GraphQl\Model\Query\ContextParametersInterface;
use PHPUnit\Framework\TestCase;

class CustomerContextFromTokenTest extends TestCase
{
    private function plugin(?array $claims, Session $session, HttpContext $httpContext): CustomerContextFromToken
    {
        $customerClaims = $this->createMock(CustomerClaims::class);
        $customerClaims->method('get')->with(7, UserContextInterface::USER_TYPE_CUSTOMER)->willReturn($claims);
        $plugin = new CustomerContextFromToken($customerClaims, $session, $httpContext);
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUserId')->willReturn(7);
        $userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_CUSTOMER);
        $plugin->beforeSetUserContext($this->createMock(AddUserInfoToContext::class), $userContext);

        return $plugin;
    }

    public function testSeedsContextAndSessionFromTheClaims(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('setCustomerId')->with(7);
        $session->expects(self::once())->method('setCustomerGroupId')->with(2);
        $httpContext = $this->createMock(HttpContext::class);
        $httpContext->expects(self::once())->method('setValue')->with(Context::CONTEXT_GROUP, '2', 0);
        $parameters = $this->createMock(ContextParametersInterface::class);
        $parameters->expects(self::once())->method('setUserId')->with(7);
        $parameters->expects(self::once())->method('setUserType')->with(UserContextInterface::USER_TYPE_CUSTOMER);
        $parameters->expects(self::once())->method('addExtensionAttribute')->with('is_customer', true);
        $plugin = $this->plugin(['uid' => 7, 'gid' => 2, 'is_customer' => true], $session, $httpContext);

        self::assertSame($parameters, $plugin->aroundExecute(
            $this->createMock(AddUserInfoToContext::class),
            static fn() => self::fail('the token answers'),
            $parameters
        ));
    }

    public function testAnotherWebsitesCustomerIsNoCustomerHere(): void
    {
        $session = $this->createMock(Session::class);
        $session->expects(self::never())->method('setCustomerId');
        $parameters = $this->createMock(ContextParametersInterface::class);
        $parameters->expects(self::once())->method('addExtensionAttribute')->with('is_customer', false);
        $plugin = $this->plugin(['uid' => 7, 'gid' => 2, 'is_customer' => false], $session, $this->createMock(HttpContext::class));

        $plugin->aroundExecute($this->createMock(AddUserInfoToContext::class), static fn() => self::fail('the token answers'), $parameters);
    }

    public function testAUserWithoutClaimsGoesToCore(): void
    {
        $parameters = $this->createMock(ContextParametersInterface::class);
        $parameters->expects(self::never())->method('setUserId');
        $proceed = static fn(ContextParametersInterface $p) => $p;
        $plugin = $this->plugin(null, $this->createMock(Session::class), $this->createMock(HttpContext::class));

        self::assertSame($parameters, $plugin->aroundExecute($this->createMock(AddUserInfoToContext::class), $proceed, $parameters));
        $plugin->_resetState();
        self::assertSame($parameters, $plugin->aroundExecute($this->createMock(AddUserInfoToContext::class), $proceed, $parameters));
    }
}
