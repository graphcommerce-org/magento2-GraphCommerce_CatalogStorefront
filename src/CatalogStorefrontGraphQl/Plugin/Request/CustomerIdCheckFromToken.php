<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Request\CustomerClaims;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Session;

/**
 * The session proves a customer id by loading the customer before it answers
 * its own id, which every taxed amount asks for. A validated token names the
 * customer; another id goes to core.
 */
class CustomerIdCheckFromToken
{
    public function __construct(private readonly CustomerClaims $claims)
    {
    }

    public function aroundCheckCustomerId(Session $subject, \Closure $proceed, $customerId): bool
    {
        return $this->claims->get((int)$customerId, UserContextInterface::USER_TYPE_CUSTOMER) !== null
            || (bool)$proceed($customerId);
    }
}
