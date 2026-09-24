<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;

class MemoTest extends TestCase
{
    public function testAMemoLivesUntilItsGenerationIsBumped(): void
    {
        $store = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(static function (string $id) use (&$store) {
            return $store[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(static function (string $data, string $id) use (&$store): bool {
            $store[$id] = $data;

            return true;
        });
        $cache->method('remove')->willReturnCallback(static function (string $id) use (&$store): bool {
            unset($store[$id]);

            return true;
        });
        $generations = new Generation($cache);
        $memo = new Memo($generations, Generation::CONFIG, 2);

        $calls = 0;
        $compute = static function () use (&$calls): int {
            return ++$calls;
        };
        self::assertSame(1, $memo->get('a', $compute));
        self::assertSame(1, $memo->get('a', $compute));
        $generations->_resetState();
        self::assertSame(1, $memo->get('a', $compute));

        $generations->bump(Generation::CONFIG);
        self::assertSame(2, $memo->get('a', $compute));

        // The limit drops every entry.
        self::assertSame(3, $memo->get('b', $compute));
        self::assertSame(4, $memo->get('c', $compute));
        self::assertSame(5, $memo->get('a', $compute));

        $memo->forget('a');
        self::assertSame(6, $memo->get('a', $compute));
    }
}
