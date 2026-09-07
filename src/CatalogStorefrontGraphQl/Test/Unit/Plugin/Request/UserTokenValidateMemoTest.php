<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request\UserTokenValidateMemo;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Integration\Api\Data\UserToken;
use Magento\Integration\Model\CompositeUserTokenValidator;
use PHPUnit\Framework\TestCase;

class UserTokenValidateMemoTest extends TestCase
{
    public function testValidatesATokenObjectOncePerRequest(): void
    {
        $runs = 0;
        $proceed = static function (UserToken $token) use (&$runs): void {
            $runs++;
        };
        $memo = new UserTokenValidateMemo();
        $validator = $this->createMock(CompositeUserTokenValidator::class);
        $a = $this->createMock(UserToken::class);
        $b = $this->createMock(UserToken::class);

        $memo->aroundValidate($validator, $proceed, $a);
        $memo->aroundValidate($validator, $proceed, $a);
        $memo->aroundValidate($validator, $proceed, $b);
        $memo->_resetState();
        $memo->aroundValidate($validator, $proceed, $a);

        self::assertSame(3, $runs);
    }

    public function testAFailedValidationRunsAgain(): void
    {
        $runs = 0;
        $proceed = static function (UserToken $token) use (&$runs): void {
            $runs++;
            throw new AuthorizationException(__('revoked'));
        };
        $memo = new UserTokenValidateMemo();
        $validator = $this->createMock(CompositeUserTokenValidator::class);
        $token = $this->createMock(UserToken::class);

        foreach ([1, 2] as $attempt) {
            try {
                $memo->aroundValidate($validator, $proceed, $token);
                self::fail('validation passed');
            } catch (AuthorizationException) {
            }
        }

        self::assertSame(2, $runs);
    }
}
