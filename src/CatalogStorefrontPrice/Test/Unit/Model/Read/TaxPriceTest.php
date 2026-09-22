<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPrice\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontPrice\Model\Read\TaxPrice;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\Data\RegionInterface;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The numbers core's catalog helper answers for a unit price without rounding,
 * per calculation algorithm and tax setup.
 */
class TaxPriceTest extends TestCase
{
    private Calculation&MockObject $calculation;
    private Session&MockObject $session;
    private GroupRepositoryInterface&MockObject $groups;

    private function taxPrice(bool $priceIncludesTax, string $algorithm = Calculation::CALC_TOTAL_BASE, bool $crossBorder = false, float $rate = 21.0, float $storeRate = 21.0): TaxPrice
    {
        $config = $this->createMock(TaxConfig::class);
        $config->method('needPriceConversion')->willReturn(true);
        $config->method('priceIncludesTax')->willReturn($priceIncludesTax);
        $config->method('getAlgorithm')->willReturn($algorithm);
        $config->method('crossBorderTradeEnabled')->willReturn($crossBorder);

        $this->calculation = $this->createMock(Calculation::class);
        $this->calculation->method('getRateRequest')->willReturn(new DataObject(['country_id' => 'NL']));
        $this->calculation->method('getRate')->willReturn($rate);
        $this->calculation->method('getStoreRate')->willReturn($storeRate);
        $this->calculation->method('getAppliedRates')->willReturn([['id' => 'a', 'percent' => $rate - 1.0], ['id' => 'b', 'percent' => 1.0]]);
        $this->calculation->method('round')->willReturnCallback(static fn(float $price): float => round($price, 2));
        $this->calculation->method('calcTaxAmount')->willReturnCallback(
            static fn(float $price, float $taxRate, bool $priceIncludeTax): float => $priceIncludeTax
                ? $price * (1 - 1 / (1 + $taxRate / 100))
                : $price * ($taxRate / 100)
        );

        $this->session = $this->createMock(Session::class);
        $this->groups = $this->createMock(GroupRepositoryInterface::class);
        $addresses = $this->createMock(AddressInterfaceFactory::class);
        $addresses->method('create')->willReturnCallback(function (): AddressInterface {
            $address = $this->createStub(AddressInterface::class);
            $address->method('setCountryId')->willReturnSelf();
            $address->method('setPostcode')->willReturnSelf();
            $address->method('setRegion')->willReturnSelf();

            return $address;
        });
        $regions = $this->createMock(RegionInterfaceFactory::class);
        $regions->method('create')->willReturnCallback(function (): RegionInterface {
            $region = $this->createStub(RegionInterface::class);
            $region->method('setRegionId')->willReturnSelf();

            return $region;
        });

        return new TaxPrice($this->calculation, $config, $this->session, $this->groups, $addresses, $regions);
    }

    private function product(int $taxClassId = 2): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('__call')->willReturnCallback(static fn(string $method): ?int => $method === 'getTaxClassId' ? $taxClassId : null);

        return $product;
    }

    private function store(): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);

        return $store;
    }

    public function testPricesIncludingTaxRoundTheUnitPriceAndBackOutTheTax(): void
    {
        $taxPrice = $this->taxPrice(true);

        self::assertSame(12.35, $taxPrice->of(12.345, true, $this->product(), $this->store()));
        self::assertEqualsWithDelta(12.35 / 1.21, $taxPrice->of(12.345, false, $this->product(), $this->store()), 1e-9);
    }

    public function testTheUnitBasedAlgorithmTakesTheUnitPriceAsItIs(): void
    {
        $taxPrice = $this->taxPrice(true, Calculation::CALC_UNIT_BASE);

        self::assertSame(12.345, $taxPrice->of(12.345, true, $this->product(), $this->store()));
    }

    public function testAnotherDestinationRateReplacesTheStoreTax(): void
    {
        $taxPrice = $this->taxPrice(true, rate: 19.0, storeRate: 21.0);

        $exclTax = 12.1 / 1.21;
        self::assertEqualsWithDelta($exclTax * 1.19, $taxPrice->of(12.1, true, $this->product(), $this->store()), 1e-9);
        self::assertEqualsWithDelta($exclTax, $taxPrice->of(12.1, false, $this->product(), $this->store()), 1e-9);
    }

    public function testCrossBorderTradeKeepsTheStorePrice(): void
    {
        $taxPrice = $this->taxPrice(true, crossBorder: true, rate: 19.0, storeRate: 21.0);

        self::assertSame(12.1, $taxPrice->of(12.1, true, $this->product(), $this->store()));
    }

    public function testPricesExcludingTaxAddEveryAppliedRate(): void
    {
        $taxPrice = $this->taxPrice(false);

        self::assertSame(10.0, $taxPrice->of(10.004, false, $this->product(), $this->store()));
        self::assertEqualsWithDelta(10.0 + 10.0 * 0.20 + 10.0 * 0.01, $taxPrice->of(10.004, true, $this->product(), $this->store()), 1e-9);
    }

    public function testAZeroAmountAndAStoreWithoutConversionStayAsTheyAre(): void
    {
        $taxPrice = $this->taxPrice(true);
        self::assertSame(0.0, $taxPrice->of(0.0, true, $this->product(), $this->store()));

        $config = $this->createMock(TaxConfig::class);
        $config->method('needPriceConversion')->willReturn(false);
        $plain = new TaxPrice(
            $this->createMock(Calculation::class),
            $config,
            $this->createMock(Session::class),
            $this->createMock(GroupRepositoryInterface::class),
            $this->createMock(AddressInterfaceFactory::class),
            $this->createMock(RegionInterfaceFactory::class)
        );
        self::assertSame(12.345, $plain->of(12.345, true, $this->product(), $this->store()));
    }

    public function testTheRateRequestIsBuiltOncePerStoreFromTheSession(): void
    {
        $taxPrice = $this->taxPrice(true);
        $this->session->method('getCustomerGroupId')->willReturn(3);
        $this->session->method('getCustomerId')->willReturn(42);
        $this->session->method('__call')->willReturnCallback(static fn(string $method): ?array => match ($method) {
            'getDefaultTaxShippingAddress' => ['country_id' => 'DE', 'postcode' => '10115', 'region_id' => 82],
            'getDefaultTaxBillingAddress' => null,
            default => null,
        });
        $group = $this->createStub(GroupInterface::class);
        $group->method('getTaxClassId')->willReturn(7);
        $this->groups->expects(self::once())->method('getById')->with(3)->willReturn($group);
        $this->calculation->expects(self::once())->method('getRateRequest')
            ->with(self::isInstanceOf(AddressInterface::class), null, 7, 1, 42)
            ->willReturn(new DataObject());

        $taxPrice->of(10.0, true, $this->product(2), $this->store());
        $taxPrice->of(20.0, false, $this->product(4), $this->store());
    }

    public function testAGuestTakesTheDefaultCustomerTaxClass(): void
    {
        $taxPrice = $this->taxPrice(true);
        $this->session->method('getCustomerGroupId')->willReturn(0);
        $this->session->method('getCustomerId')->willReturn(null);
        $this->groups->expects(self::never())->method('getById');
        $this->calculation->expects(self::once())->method('getRateRequest')->with(null, null, null, 1, null)->willReturn(new DataObject());

        $taxPrice->of(10.0, true, $this->product(), $this->store());
    }
}
