<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Token;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Group\Resolver as GroupResolver;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\Jwt\Claim\PrivateClaim;
use Magento\Integration\Api\Data\UserTokenParametersInterface;
use Magento\JwtUserToken\Model\Data\JwtTokenParameters;
use Magento\JwtUserToken\Model\Issuer;

/**
 * A customer JWT carries the group (gid) and the website (wid) next to the
 * user id, so a request reads them from the token. Both are read once, when
 * the token is issued; a group change reaches the storefront with the next
 * token.
 */
class CustomerClaimsIntoToken
{
    public function __construct(
        private readonly GroupResolver $groupResolver,
        private readonly CustomerResource $customerResource,
    ) {
    }

    public function beforeCreate(Issuer $subject, UserContextInterface $userContext, UserTokenParametersInterface $params): array
    {
        $userId = (int)$userContext->getUserId();
        $groupId = (int)$userContext->getUserType() === UserContextInterface::USER_TYPE_CUSTOMER
            ? $this->groupResolver->resolve($userId)
            : null;
        if ($groupId === null) {
            return [$userContext, $params];
        }
        $extension = $params->getExtensionAttributes();
        $jwtParams = $extension->getJwtParams() ?? new JwtTokenParameters();
        $jwtParams->setClaims(array_merge($jwtParams->getClaims(), [
            'gid' => new PrivateClaim('gid', $groupId),
            'wid' => new PrivateClaim('wid', (int)$this->customerResource->getWebsiteId($userId)),
        ]));
        $extension->setJwtParams($jwtParams);

        return [$userContext, $params];
    }
}
