<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Request\CustomerClaims;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Session;
use Magento\CustomerGraphQl\Model\Context\AddUserInfoToContext;
use Magento\Framework\App\ResponseInterface;
use Magento\GraphQl\Controller\GraphQl;

/**
 * Core's session cleanup after dispatch sets the session back to the
 * request's customer by loading that customer twice (the id check, then the
 * data). The token's claims name the customer and the group. A user without
 * claims takes core's path.
 */
class CustomerSessionAfterDispatch
{
    public function __construct(
        private readonly CustomerClaims $claims,
        private readonly UserContextInterface $userContext,
        private readonly Session $session,
        private readonly AddUserInfoToContext $addUserInfoToContext,
    ) {
    }

    public function afterDispatch(GraphQl $subject, ResponseInterface $response): ResponseInterface
    {
        $claims = $this->claims->get((int)$this->userContext->getUserId(), (int)$this->userContext->getUserType());
        if ($claims === null) {
            $customer = $this->addUserInfoToContext->getLoggedInCustomerData();
            $this->session->setCustomerId($customer?->getId());
            $this->session->setCustomerGroupId($customer?->getGroupId());
        } else {
            $this->session->setCustomerId($claims['is_customer'] ? $claims['uid'] : null);
            $this->session->setCustomerGroupId($claims['is_customer'] ? $claims['gid'] : null);
        }

        return $response;
    }
}
