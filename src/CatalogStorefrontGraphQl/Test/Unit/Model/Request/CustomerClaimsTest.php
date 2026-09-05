<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Request\CustomerClaims;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Jwt\Claim\PrivateClaim;
use Magento\Framework\Jwt\HeaderInterface;
use Magento\Framework\Jwt\Payload\ClaimsPayloadInterface;
use Magento\Integration\Api\Data\UserToken;
use Magento\Integration\Api\Data\UserTokenDataInterface;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\JwtUserToken\Model\Data\JwtTokenData;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class CustomerClaimsTest extends TestCase
{
    private function claims(UserTokenDataInterface $data, bool $websiteScope = true, string $header = 'Bearer t0k3n'): CustomerClaims
    {
        $request = $this->createMock(Http::class);
        $request->method('getHeader')->with('Authorization')->willReturn($header);
        $token = $this->createMock(UserToken::class);
        $token->method('getData')->willReturn($data);
        $reader = $this->createMock(UserTokenReaderInterface::class);
        $reader->method('read')->with('t0k3n')->willReturn($token);
        $share = $this->createMock(Share::class);
        $share->method('isWebsiteScope')->willReturn($websiteScope);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new CustomerClaims($request, $reader, $share, $storeManager);
    }

    private function jwt(array $claims): JwtTokenData
    {
        $payload = $this->createMock(ClaimsPayloadInterface::class);
        $payload->method('getClaims')->willReturn($claims);
        $now = new \DateTimeImmutable();

        return new JwtTokenData($now, $now, $this->createMock(HeaderInterface::class), $payload);
    }

    private function customerToken(int $websiteId = 1): JwtTokenData
    {
        return $this->jwt([
            'uid' => new PrivateClaim('uid', 7),
            'gid' => new PrivateClaim('gid', 2),
            'wid' => new PrivateClaim('wid', $websiteId),
        ]);
    }

    public function testReadsTheGroupAndTheWebsiteMatchFromTheToken(): void
    {
        $customer = UserContextInterface::USER_TYPE_CUSTOMER;

        self::assertSame(['uid' => 7, 'gid' => 2, 'is_customer' => true], $this->claims($this->customerToken())->get(7, $customer));
        self::assertSame(['uid' => 7, 'gid' => 2, 'is_customer' => false], $this->claims($this->customerToken(2))->get(7, $customer));
        self::assertSame(['uid' => 7, 'gid' => 2, 'is_customer' => true], $this->claims($this->customerToken(2), false)->get(7, $customer));
    }

    public function testOtherUsersAndTokensWithoutClaimsHaveNone(): void
    {
        $customer = UserContextInterface::USER_TYPE_CUSTOMER;

        self::assertNull($this->claims($this->customerToken())->get(null, null));
        self::assertNull($this->claims($this->customerToken())->get(7, UserContextInterface::USER_TYPE_INTEGRATION));
        self::assertNull($this->claims($this->customerToken())->get(8, $customer));
        self::assertNull($this->claims($this->jwt(['uid' => new PrivateClaim('uid', 7)]))->get(7, $customer));
        self::assertNull($this->claims($this->createMock(UserTokenDataInterface::class))->get(7, $customer));
        self::assertNull($this->claims($this->customerToken(), true, '')->get(7, $customer));
    }
}
