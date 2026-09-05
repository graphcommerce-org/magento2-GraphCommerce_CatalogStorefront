<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Token;

use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Token\RevokeOnGroupChange;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\Group\Resolver;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Integration\Api\UserTokenRevokerInterface;
use PHPUnit\Framework\TestCase;

class RevokeOnGroupChangeTest extends TestCase
{
    private array $revoked = [];

    private function plugin(?int $groupBefore): RevokeOnGroupChange
    {
        $groups = $this->createMock(Resolver::class);
        $groups->method('resolve')->willReturn($groupBefore);
        $revoker = $this->createMock(UserTokenRevokerInterface::class);
        $revoker->method('revokeFor')->willReturnCallback(function (UserContextInterface $context): void {
            $this->revoked[] = [$context->getUserId(), $context->getUserType()];
        });

        return new RevokeOnGroupChange($groups, $revoker);
    }

    private function customer(?int $id, int $groupId): Customer
    {
        $customer = $this->createMock(Customer::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getGroupId')->willReturn($groupId);

        return $customer;
    }

    public function testAGroupChangeRevokesTheCustomersTokens(): void
    {
        $resource = $this->createMock(CustomerResource::class);
        $proceed = static fn(Customer $customer): CustomerResource => $resource;

        self::assertSame($resource, $this->plugin(1)->aroundSave($resource, $proceed, $this->customer(7, 2)));
        self::assertSame([[7, UserContextInterface::USER_TYPE_CUSTOMER]], $this->revoked);
    }

    public function testTheSameGroupAndNewCustomersKeepTheirTokens(): void
    {
        $resource = $this->createMock(CustomerResource::class);
        $proceed = static fn(Customer $customer): CustomerResource => $resource;

        $this->plugin(2)->aroundSave($resource, $proceed, $this->customer(7, 2));
        $this->plugin(null)->aroundSave($resource, $proceed, $this->customer(null, 2));

        self::assertSame([], $this->revoked);
    }
}
