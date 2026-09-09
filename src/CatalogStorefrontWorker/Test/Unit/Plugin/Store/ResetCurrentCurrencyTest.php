<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Test\Unit\Plugin\Store;

use GraphCommerce\CatalogStorefrontWorker\Plugin\Store\ResetCurrentCurrency;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\PriceCurrency;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResetCurrentCurrencyTest extends TestCase
{
    public function testCoreResetRebuildsCurrencyForEveryRetainedStore(): void
    {
        $usd = $this->currency('USD');
        $eur = $this->currency('EUR');
        $base = $this->getMockBuilder(Currency::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRate'])
            ->getMock();
        $base->expects($this->exactly(10))->method('getRate')
            ->willReturnCallback(static fn(Currency $to): float => $to === $eur ? 0.85 : 1.0);
        $currencyFactory = $this->createMock(CurrencyFactory::class);
        $currencyFactory->expects($this->exactly(4))
            ->method('create')
            ->willReturnOnConsecutiveCalls($usd, $usd, $eur, $eur);

        $requestedCurrency = 'USD';
        $defaultStore = $this->store($currencyFactory, $base, $requestedCurrency);
        $nonDefaultStore = $this->store($currencyFactory, $base, $requestedCurrency);
        $plugin = new ResetCurrentCurrency();
        $priceCurrency = new PriceCurrency(
            $this->createStub(StoreManagerInterface::class),
            $currencyFactory,
            $this->createStub(LoggerInterface::class),
        );

        self::assertSame(6.0, $priceCurrency->convert(6, $defaultStore));
        self::assertSame(6.0, $priceCurrency->convert(6, $nonDefaultStore));
        $plugin->afterGetCurrentCurrency($defaultStore, $defaultStore->getCurrentCurrency());
        $plugin->afterGetCurrentCurrency($nonDefaultStore, $nonDefaultStore->getCurrentCurrency());
        $requestedCurrency = 'EUR';
        self::assertSame(6.0, $priceCurrency->convert(6, $defaultStore));
        self::assertSame(6.0, $priceCurrency->convert(6, $nonDefaultStore));

        foreach ([$defaultStore, $nonDefaultStore] as $store) {
            $store->_resetState();
            self::assertSame($base, $store->getData('base_currency'));
        }
        $plugin->_resetState();

        self::assertSame(5.1, $priceCurrency->convert(6, $defaultStore));
        self::assertSame(5.1, $priceCurrency->convert(6, $nonDefaultStore));
    }

    private function store(CurrencyFactory $currencyFactory, Currency $base, string &$requestedCurrency): Store
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrentCurrencyCode', 'getBaseCurrency'])
            ->getMock();
        $store->expects($this->exactly(2))->method('getCurrentCurrencyCode')
            ->willReturnCallback(static function () use (&$requestedCurrency): string {
                return $requestedCurrency;
            });
        $store->expects($this->exactly(5))->method('getBaseCurrency')->willReturn($base);
        (new \ReflectionProperty(Store::class, 'currencyFactory'))->setValue($store, $currencyFactory);
        $store->setData('base_currency', $base);

        return $store;
    }

    private function currency(string $code): Currency
    {
        $currency = $this->getMockBuilder(Currency::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['load'])
            ->getMock();
        $currency->expects($this->exactly(2))->method('load')->with($code)->willReturnSelf();

        return $currency;
    }
}
