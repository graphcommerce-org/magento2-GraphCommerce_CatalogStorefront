<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Integration\Api\Data\UserToken;
use Magento\Integration\Model\CompositeTokenReader;

/**
 * Core reads the bearer token three times per GraphQL request (the request
 * validator twice, the user context once); each read is a token table lookup,
 * a JWT parse and a revocation check. One read per request serves them all.
 */
class UserTokenMemo implements ResetAfterRequestInterface
{
    private array $tokens = [];

    public function aroundRead(CompositeTokenReader $subject, \Closure $proceed, string $token): UserToken
    {
        return $this->tokens[$token] ??= $proceed($token);
    }

    public function _resetState(): void
    {
        $this->tokens = [];
    }
}
