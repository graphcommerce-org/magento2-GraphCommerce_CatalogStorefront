<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Request\CustomerClaims;
use Magento\CustomerGraphQl\Model\Context\AddCustomerGroupToContext;
use Magento\GraphQl\Model\Query\ContextParametersInterface;

/**
 * The context's customer group comes from the token's claim; core reads the
 * group column for it.
 */
class CustomerGroupFromToken
{
    public function __construct(private readonly CustomerClaims $claims)
    {
    }

    public function aroundExecute(
        AddCustomerGroupToContext $subject,
        \Closure $proceed,
        ContextParametersInterface $parameters
    ): ContextParametersInterface {
        $claims = $this->claims->get($parameters->getUserId(), $parameters->getUserType());
        if ($claims === null || !$claims['is_customer']) {
            return $proceed($parameters);
        }
        $parameters->addExtensionAttribute('customer_group_id', $claims['gid']);

        return $parameters;
    }
}
