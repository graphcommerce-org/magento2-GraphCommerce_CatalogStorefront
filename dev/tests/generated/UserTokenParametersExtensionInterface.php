<?php
/**
 * The extension interface Magento generates for the token parameters, for
 * the unit tests of an installation without generated code.
 */
declare(strict_types=1);

namespace Magento\Integration\Api\Data;

interface UserTokenParametersExtensionInterface extends \Magento\Framework\Api\ExtensionAttributesInterface
{
    /** @return \Magento\JwtUserToken\Model\Data\JwtTokenParameters|null */
    public function getJwtParams();

    /** @param \Magento\JwtUserToken\Model\Data\JwtTokenParameters $jwtParams */
    public function setJwtParams(\Magento\JwtUserToken\Model\Data\JwtTokenParameters $jwtParams);
}
