<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model\Read\DocumentLowestPriceOptionsProvider;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Pricing\Price\LowestPriceOptionsProvider;
use Magento\Customer\Model\Session as CustomerSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DocumentLowestPriceOptionsProviderTest extends TestCase
{
    private LowestPriceOptionsProvider&MockObject $core;

    private function provider(): DocumentLowestPriceOptionsProvider
    {
        $this->core = $this->createMock(LowestPriceOptionsProvider::class);

        $session = $this->createMock(CustomerSession::class);
        $session->method('getCustomerGroupId')->willReturn(0);

        return new DocumentLowestPriceOptionsProvider(
            $this->core,
            new ProductPrice(),
            $session,
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
    private function parent(array $children, bool $withDocument = true): Product
    {
        $type = $this->createMock(Configurable::class);
        $type->method('getUsedProducts')->willReturn($children);

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(100);
        $product->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $product->method('getTypeInstance')->willReturn($type);
        $product->method('getData')->willReturnCallback(
            static fn(string $key) => $key === ProductDocumentsInterface::DOCUMENT_KEY && $withDocument
                ? ['type' => Configurable::TYPE_CODE]
                : null
        );

        return $product;
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
