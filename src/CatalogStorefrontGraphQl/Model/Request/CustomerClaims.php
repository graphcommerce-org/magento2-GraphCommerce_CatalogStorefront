<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Request;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\App\Request\Http;
use Magento\Integration\Api\UserTokenReaderInterface;
use Magento\JwtUserToken\Model\Data\JwtTokenData;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The group claim of the request's customer JWT and whether its website
 * claim makes the user a customer of the current website, read through the
 * token reader that already parsed the token for the user context. Null
 * when the asked user is not the token's customer: guests, integrations,
 * the contexts core builds after issuing or revoking a token, opaque
 * tokens and tokens issued without the claims.
 */
class CustomerClaims
{
    public function __construct(
        private readonly Http $request,
        private readonly UserTokenReaderInterface $tokenReader,
        private readonly Share $configShare,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /** @return array{uid: int, gid: int, is_customer: bool}|null */
    public function get(?int $userId, ?int $userType): ?array
    {
        if (!$userId || $userType !== UserContextInterface::USER_TYPE_CUSTOMER) {
            return null;
        }
        $header = explode(' ', (string)$this->request->getHeader('Authorization'));
        if (count($header) !== 2 || strtolower($header[0]) !== 'bearer') {
            return null;
        }
        $data = $this->tokenReader->read($header[1])->getData();
        if (!$data instanceof JwtTokenData) {
            return null;
        }
        $claims = $data->getJwtClaims()->getClaims();
        if (!isset($claims['uid'], $claims['gid'], $claims['wid']) || (int)$claims['uid']->getValue() !== $userId) {
            return null;
        }

        return [
            'uid' => $userId,
            'gid' => (int)$claims['gid']->getValue(),
            'is_customer' => !$this->configShare->isWebsiteScope()
                || (int)$claims['wid']->getValue() === (int)$this->storeManager->getStore()->getWebsiteId(),
        ];
    }
}
