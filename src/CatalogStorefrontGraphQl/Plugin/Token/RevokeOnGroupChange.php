<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Token;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Group\Resolver as GroupResolver;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\Model\AbstractModel;
use Magento\Integration\Api\UserTokenRevokerInterface;
use Magento\Integration\Model\CustomUserContext;

/**
 * A customer JWT carries the group, so a save that moves the customer to
 * another group (an admin edit, the VAT validation of an address) revokes
 * the customer's tokens: the next sign-in issues a token with the new group.
 * The group before the save is one column read.
 */
class RevokeOnGroupChange
{
    public function __construct(
        private readonly GroupResolver $groupResolver,
        private readonly UserTokenRevokerInterface $revoker,
    ) {
    }

    public function aroundSave(CustomerResource $subject, \Closure $proceed, AbstractModel $customer)
    {
        $customerId = (int)$customer->getId();
        $groupBefore = $customerId ? $this->groupResolver->resolve($customerId) : null;
        $result = $proceed($customer);
        if ($groupBefore !== null && (int)$customer->getGroupId() !== $groupBefore) {
            $this->revoker->revokeFor(new CustomUserContext($customerId, UserContextInterface::USER_TYPE_CUSTOMER));
        }

        return $result;
    }
}
