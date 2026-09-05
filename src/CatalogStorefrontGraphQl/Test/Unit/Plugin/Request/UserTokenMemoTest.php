<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Request;

use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Request\UserTokenMemo;
use Magento\Integration\Api\Data\UserToken;
use Magento\Integration\Model\CompositeTokenReader;
use PHPUnit\Framework\TestCase;

class UserTokenMemoTest extends TestCase
{
    public function testReadsATokenOncePerRequest(): void
    {
        $reads = [];
        $proceed = function (string $token) use (&$reads): UserToken {
            $reads[] = $token;

            return $this->createMock(UserToken::class);
        };
        $memo = new UserTokenMemo();
        $reader = $this->createMock(CompositeTokenReader::class);

        $first = $memo->aroundRead($reader, $proceed, 'a');
        self::assertSame($first, $memo->aroundRead($reader, $proceed, 'a'));
        $memo->aroundRead($reader, $proceed, 'b');
        $memo->_resetState();
        $memo->aroundRead($reader, $proceed, 'a');

        self::assertSame(['a', 'b', 'a'], $reads);
    }

    public function testAFailedReadIsNotKept(): void
    {
        $memo = new UserTokenMemo();
        $reader = $this->createMock(CompositeTokenReader::class);
        $proceed = static fn(string $token): UserToken => throw new \RuntimeException('bad token');

        $this->expectException(\RuntimeException::class);
        $memo->aroundRead($reader, $proceed, 'x');
    }
}
