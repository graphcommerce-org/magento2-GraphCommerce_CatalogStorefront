<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Token;

use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Token\CustomerClaimsIntoToken;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Group\Resolver;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\Jwt\Claim\PrivateClaim;
use Magento\Integration\Api\Data\UserTokenParametersExtensionInterface;
use Magento\Integration\Api\Data\UserTokenParametersInterface;
use Magento\JwtUserToken\Model\Data\JwtTokenParameters;
use Magento\JwtUserToken\Model\Issuer;
use PHPUnit\Framework\TestCase;

class CustomerClaimsIntoTokenTest extends TestCase
{
    private function plugin(?int $groupId): CustomerClaimsIntoToken
    {
        $groups = $this->createMock(Resolver::class);
        $groups->method('resolve')->with(7)->willReturn($groupId);
        $resource = $this->createMock(CustomerResource::class);
        $resource->method('getWebsiteId')->with(7)->willReturn('1');

        return new CustomerClaimsIntoToken($groups, $resource);
    }

    private function params(?JwtTokenParameters $existing, ?JwtTokenParameters &$stored): UserTokenParametersInterface
    {
        $extension = $this->createMock(UserTokenParametersExtensionInterface::class);
        $extension->method('getJwtParams')->willReturn($existing);
        $extension->method('setJwtParams')->willReturnCallback(function (JwtTokenParameters $jwtParams) use (&$stored): void {
            $stored = $jwtParams;
        });
        $params = $this->createMock(UserTokenParametersInterface::class);
        $params->method('getExtensionAttributes')->willReturn($extension);

        return $params;
    }

    public function testACustomerTokenCarriesGroupAndWebsite(): void
    {
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUserId')->willReturn(7);
        $userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_CUSTOMER);
        $existing = new JwtTokenParameters();
        $existing->setClaims(['aud' => new PrivateClaim('aud', 'shop')]);
        $stored = null;
        $params = $this->params($existing, $stored);

        $this->plugin(2)->beforeCreate($this->createMock(Issuer::class), $userContext, $params);

        $claims = $stored->getClaims();
        self::assertSame(['aud', 'gid', 'wid'], array_keys($claims));
        self::assertSame(2, $claims['gid']->getValue());
        self::assertSame(1, $claims['wid']->getValue());
    }

    public function testAdminAndUnknownCustomerTokensStayAsTheyAre(): void
    {
        $stored = null;
        $admin = $this->createMock(UserContextInterface::class);
        $admin->method('getUserId')->willReturn(1);
        $admin->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_ADMIN);
        $this->plugin(2)->beforeCreate($this->createMock(Issuer::class), $admin, $this->params(null, $stored));
        self::assertNull($stored);

        $unknown = $this->createMock(UserContextInterface::class);
        $unknown->method('getUserId')->willReturn(7);
        $unknown->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_CUSTOMER);
        $this->plugin(null)->beforeCreate($this->createMock(Issuer::class), $unknown, $this->params(null, $stored));
        self::assertNull($stored);
    }
}
