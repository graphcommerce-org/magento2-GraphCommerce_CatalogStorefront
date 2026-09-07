<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Integration\Api\Data\UserToken;
use Magento\Integration\Model\CompositeUserTokenValidator;

/**
 * Each of the three token reads per request validates the token again, and
 * the revocation validator reads the revoked table each time because only
 * revoked entries are cached. One validation per token object per request.
 */
class UserTokenValidateMemo implements ResetAfterRequestInterface
{
    private \SplObjectStorage $validated;

    public function __construct()
    {
        $this->validated = new \SplObjectStorage();
    }

    public function aroundValidate(CompositeUserTokenValidator $subject, \Closure $proceed, UserToken $token): void
    {
        if ($this->validated->contains($token)) {
            return;
        }
        $proceed($token);
        $this->validated->attach($token);
    }

    public function _resetState(): void
    {
        $this->validated = new \SplObjectStorage();
    }
}
