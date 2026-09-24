<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model\Read\DocumentLowestPriceOptionsProvider;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Pricing\Price\LowestPriceOptionsProvider;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DocumentLowestPriceOptionsProviderTest extends TestCase
{
    private LowestPriceOptionsProvider&MockObject $core;

    private ListingDocuments $listing;

    private bool $showOutOfStock = false;

    private bool $weee = false;

    /** @var array<int, array<string, mixed>> the data of every child the provider built from a range */
    private array $built = [];

    private function provider(): DocumentLowestPriceOptionsProvider
    {
        $this->core = $this->createMock(LowestPriceOptionsProvider::class);
        $this->listing = new ListingDocuments();

        $session = $this->createMock(CustomerSession::class);
        $session->method('getCustomerGroupId')->willReturn(0);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $stock = $this->createMock(StockConfigurationInterface::class);
        $stock->method('isShowOutOfStock')->willReturnCallback(fn () => $this->showOutOfStock);

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturnCallback(fn () => $this->weee);

        $factory = $this->createMock(ProductFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $child = $this->createMock(Product::class);
            $child->method('setData')->willReturnCallback(function (array $data) use ($child) {
                $this->built[] = $data;

                return $child;
            });

            return $child;
        });

        return new DocumentLowestPriceOptionsProvider(
            $this->core,
            new ProductPrice(),
            $session,
            $this->listing,
            $storeManager,
            $stock,
            $config,
            $factory,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array $priceIndex the document's priceIndex, whatever shape the test wants to try
     */
    private function child(int $id, array $priceIndex): Product
    {
        $child = $this->createMock(Product::class);
        $child->method('getId')->willReturn($id);
        $child->method('getData')->willReturnCallback(
            static fn(string $key) => $key === ProductDocumentsInterface::DOCUMENT_KEY
                ? ['priceIndex' => $priceIndex]
                : null
        );

        return $child;
    }

    /**
     * @param array{regular: float, final: float} $prices
     */
    private function entries(array $prices, string $group = '0'): array
    {
        return [['group' => $group, 'regular' => $prices['regular'], 'final' => $prices['final']]];
    }

    /**
     * @param Product[] $children
     */
    private function parent(array $children, bool $withDocument = true, bool $inStock = true, bool $childrenAsked = true): Product
    {
        $type = $this->createMock(Configurable::class);
        $type->expects($childrenAsked ? $this->any() : $this->never())->method('getUsedProducts')->willReturn($children);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(100);
        $product->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $product->method('getTypeInstance')->willReturn($type);
        $product->method('getData')->willReturnCallback(
            static fn(string $key) => $key === ProductDocumentsInterface::DOCUMENT_KEY && $withDocument
                ? ['type' => Configurable::TYPE_CODE, 'inStock' => $inStock]
                : null
        );

        return $product;
    }

    /**
     * @param array<int, array{0: float, 1: float, 2: float, 3: float}> $byTaxClass
     */
    private function range(float $minRegular, float $minFinal, array $byTaxClass = []): array
    {
        return [$minRegular, $minFinal, $minRegular, $minFinal, $byTaxClass];
    }

    private function ranges(?array $salable, ?array $all = null): void
    {
        $this->listing->add('default', [], ['configurable' => [100 => ['salable' => $salable, 'all' => $all]]]);
    }

    public function testBuildsOneChildPerTaxClassFromThePageRanges(): void
    {
        $provider = $this->provider();
        $this->ranges($this->range(10.0, 8.0, [2 => [10.0, 8.0, 30.0, 25.0], 5 => [12.0, 12.0, 20.0, 20.0]]));

        $children = $provider->getProducts($this->parent([], childrenAsked: false));

        $this->assertCount(2, $children);
        $this->assertSame([
            ['type_id' => 'simple', 'store_id' => 0, 'tax_class_id' => 2, 'price' => 10.0, 'catalog_rule_price' => 8.0, 'tier_price' => []],
            ['type_id' => 'simple', 'store_id' => 0, 'tax_class_id' => 5, 'price' => 12.0, 'catalog_rule_price' => null, 'tier_price' => []],
        ], $this->built);
    }

    public function testAnOutOfStockParentPricesFromEveryChildWhenOutOfStockIsShown(): void
    {
        $provider = $this->provider();
        $this->showOutOfStock = true;
        $this->ranges(null, $this->range(15.0, 15.0));

        $provider->getProducts($this->parent([], inStock: false, childrenAsked: false));

        $this->assertSame(15.0, $this->built[0]['price']);
    }

    public function testARangeWithoutAPricedChildUsesTheChildren(): void
    {
        $provider = $this->provider();
        $this->ranges(null, $this->range(15.0, 15.0));
        $child = $this->child(1, $this->entries(['regular' => 10.0, 'final' => 10.0]));

        $this->assertSame([$child], $provider->getProducts($this->parent([$child])));
    }

    public function testFixedProductTaxesLeaveTheRangesAlone(): void
    {
        $provider = $this->provider();
        $this->weee = true;
        $this->ranges($this->range(10.0, 8.0));
        $child = $this->child(1, $this->entries(['regular' => 10.0, 'final' => 10.0]));

        $this->assertSame([$child], $provider->getProducts($this->parent([$child])));
        $this->assertSame([], $this->built);
    }

    public function testPicksTheCheapestByFinalAndByRegularWhenTheyAreDifferentChildren(): void
    {
        // ConfigurablePriceResolver minimises the final price and ConfigurableRegularPrice the
        // regular one. With per-variant discounts those are different children and both are needed.
        $cheapestFinal = $this->child(1, $this->entries(['regular' => 30.0, 'final' => 10.0]));
        $cheapestRegular = $this->child(2, $this->entries(['regular' => 20.0, 'final' => 20.0]));

        $result = $this->provider()->getProducts($this->parent([$cheapestFinal, $cheapestRegular]));

        $this->assertSame([$cheapestFinal, $cheapestRegular], $result);
    }

    public function testReturnsASingleChildWhenItIsCheapestOnBoth(): void
    {
        $cheapest = $this->child(1, $this->entries(['regular' => 10.0, 'final' => 10.0]));
        $dearer = $this->child(2, $this->entries(['regular' => 30.0, 'final' => 30.0]));

        $result = $this->provider()->getProducts($this->parent([$cheapest, $dearer]));

        $this->assertSame([$cheapest], $result);
    }

    public function testHandsBackEveryChildWhenOneHasNoPriceIndexEntry(): void
    {
        // A child that cannot be ranked makes the cheapest unknowable. Returning the whole set is
        // correct but not cheap; picking from the rest could misprice the card.
        $ranked = $this->child(1, $this->entries(['regular' => 10.0, 'final' => 10.0]));
        $unranked = $this->child(2, []);
        $children = [$ranked, $unranked];

        $result = $this->provider()->getProducts($this->parent($children));

        $this->assertSame($children, $result);
    }

    public function testIgnoresAPriceIndexEntryForAnotherCustomerGroup(): void
    {
        $child = $this->child(1, $this->entries(['regular' => 10.0, 'final' => 10.0], '5'));
        $children = [$child];

        $result = $this->provider()->getProducts($this->parent($children));

        $this->assertSame($children, $result, 'group 5 must not answer for group 0');
    }

    /**
     * priceIndex is a LIST of {group, regular, final} entries, not a map keyed by group.
     *
     * An earlier version read `$document['priceIndex'][$groupKey]`, which returns null against
     * this shape — every configurable then fell through to ranking in PHP: correct output, none
     * of the saving, and only a log line to show for it.
     */
    public function testAMapShapedPriceIndexIsNotMistakenForEntries(): void
    {
        $child = $this->child(1, ['0' => ['regular' => 10.0, 'final' => 10.0]]);
        $children = [$child];

        $result = $this->provider()->getProducts($this->parent($children));

        $this->assertSame($children, $result);
    }

    public function testFallsBackToCoreWithoutADocument(): void
    {
        $parent = $this->parent([], false);
        $provider = $this->provider();
        $expected = [$this->createMock(Product::class)];
        $this->core->expects($this->once())->method('getProducts')->with($parent)->willReturn($expected);

        $this->assertSame($expected, $provider->getProducts($parent));
    }

    public function testFallsBackToCoreWhenTheConfigurableHasNoChildren(): void
    {
        $parent = $this->parent([]);
        $provider = $this->provider();
        $expected = [$this->createMock(Product::class)];
        $this->core->expects($this->once())->method('getProducts')->with($parent)->willReturn($expected);

        $this->assertSame($expected, $provider->getProducts($parent));
    }

    public function testFallsBackToCoreForANonConfigurable(): void
    {
        $simple = $this->createMock(Product::class);
        $simple->method('getTypeId')->willReturn('simple');

        $provider = $this->provider();
        $expected = [$this->createMock(Product::class)];
        $this->core->expects($this->once())->method('getProducts')->with($simple)->willReturn($expected);

        $this->assertSame($expected, $provider->getProducts($simple));
    }
}
