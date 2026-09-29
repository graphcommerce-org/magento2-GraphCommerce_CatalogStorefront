<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model\Parity;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Parity\Picks;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock;
use Magento\Directory\Model\Currency;
use Magento\Eav\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PicksTest extends TestCase
{
    #[DataProvider('currencies')]
    public function testCurrencyRequiresAnAllowedAlternateWithARate(array $allowed, array $rates, ?string $expected): void
    {
        $base = $this->createStub(Currency::class);
        $base->method('getRate')->willReturnCallback(static fn(string $code) => $rates[$code] ?? false);
        $store = $this->createStub(Store::class);
        $store->method('getBaseCurrencyCode')->willReturn('USD');
        $store->method('getBaseCurrency')->willReturn($base);
        $store->method('getAvailableCurrencyCodes')->willReturn($allowed);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getDefaultStoreView')->willReturn($store);
        $picks = new Picks(
            $this->createStub(ResourceConnection::class),
            $this->createStub(Config::class),
            $this->createStub(Uid::class),
            $stores,
            $this->createStub(CollectionFactory::class),
            $this->createStub(Stock::class)
        );

        self::assertSame($expected, $picks->currency());
    }

    public static function currencies(): iterable
    {
        yield 'base only' => [['USD'], ['USD' => 1, 'EUR' => 0.9], null];
        yield 'missing rate' => [['USD', 'EUR'], [], null];
        yield 'zero rate' => [['USD', 'EUR'], ['EUR' => 0], null];
        yield 'valid alternate' => [['USD', 'EUR'], ['EUR' => 0.9], 'EUR'];
        yield 'valid rate after missing rate' => [['USD', 'EUR', 'GBP'], ['GBP' => 0.8], 'GBP'];
    }
}
