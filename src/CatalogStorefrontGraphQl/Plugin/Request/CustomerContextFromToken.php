<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Request\CustomerClaims;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Context;
use Magento\Customer\Model\Group;
use Magento\Customer\Model\Session;
use Magento\CustomerGraphQl\Model\Context\AddUserInfoToContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\GraphQl\Model\Query\ContextParametersInterface;

/**
 * The context factory loads the whole customer of a bearer token, addresses,
 * region and newsletter status included, to tell a customer of this website
 * from an integration and to seed the session. The token's claims answer
 * both without a read, and the session loads the customer itself when a
 * consumer asks for it. The user context the factory hands the processor
 * decides; a user without claims goes to core.
 */
class CustomerContextFromToken implements ResetAfterRequestInterface
{
    private ?UserContextInterface $userContext = null;

    public function __construct(
        private readonly CustomerClaims $claims,
        private readonly Session $session,
        private readonly HttpContext $httpContext,
    ) {
    }

    public function beforeSetUserContext(AddUserInfoToContext $subject, UserContextInterface $userContext): void
    {
        $this->userContext = $userContext;
    }

    public function aroundExecute(
        AddUserInfoToContext $subject,
        \Closure $proceed,
        ContextParametersInterface $parameters
    ): ContextParametersInterface {
        $claims = $this->userContext === null ? null : $this->claims->get(
            (int)$this->userContext->getUserId(),
            (int)$this->userContext->getUserType()
        );
        if ($claims === null) {
            return $proceed($parameters);
        }

        $parameters->setUserId($claims['uid']);
        $parameters->setUserType(UserContextInterface::USER_TYPE_CUSTOMER);
        $parameters->addExtensionAttribute('is_customer', $claims['is_customer']);
        if ($claims['is_customer']) {
            $this->session->setCustomerId($claims['uid']);
            $this->session->setCustomerGroupId($claims['gid']);
            $this->httpContext->setValue(Context::CONTEXT_GROUP, (string)$claims['gid'], Group::NOT_LOGGED_IN_ID);
        }

        return $parameters;
    }

    public function _resetState(): void
    {
        $this->userContext = null;
    }
}
