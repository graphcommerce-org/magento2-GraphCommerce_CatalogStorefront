<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductListing\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontConfigurableProductListing\Plugin\Listing\UsedProductsFromDocuments;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UsedProductsFromDocumentsTest extends TestCase
{
    private const MEMO = '_cache_instance_products';

    private ProductDocumentsInterface&MockObject $products;

    /** @var array<int, array<int, array>> setData calls per child id */
    private array $written = [];

    /**
     * @param array<int, array> $documents child documents by id
     */
    private function plugin(array $documents): UsedProductsFromDocuments
    {
        $this->products = $this->createMock(ProductDocumentsInterface::class);
        $this->products->method('documents')->willReturn($documents);
        // build() answers a model for every document it was given, keyed by product id — the
        // missing ones are what the plugin's whole-page fallback keys on.
        $this->products->method('build')->willReturnCallback(
            function ($store, array $docs): array {
                $models = [];
                foreach (array_keys($docs) as $id) {
                    $models[(int)$id] = $this->child((int)$id);
                }

                return $models;
            }
        );

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager->method('getStore')->willReturn($store);

        $session = $this->createMock(CustomerSession::class);
        $session->method('getCustomerGroupId')->willReturn(0);

        return new UsedProductsFromDocuments(
            $this->products,
            new ProductPrice(),
            $storeManager,
            $session,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function child(int $id): Product
    {
        $child = $this->createMock(Product::class);
        $child->method('getId')->willReturn($id);
        $child->method('setData')->willReturnCallback(
            function (string $key, $value) use ($id, $child) {
                $this->written[$id][] = [$key, $value];

                return $child;
            }
        );

        return $child;
    }

    /**
     * @param array<string, int|null> $variantIds as the feed writes them: "v<id>" keys, a null
     *                                            value for a link the feed reported removed
     */
    private function parent(?array $variantIds, bool $memoised = false): Product
    {
        $document = $variantIds === null ? null : ['variantIds' => $variantIds];

        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(100);
        $product->method('getStoreId')->willReturn(1);
        $product->method('hasData')->willReturnCallback(
            static fn(string $key) => $key === self::MEMO && $memoised
        );
        $product->method('getData')->willReturnCallback(
            static fn(string $key) => match ($key) {
                ProductDocumentsInterface::DOCUMENT_KEY => $document,
                self::MEMO => $memoised ? ['memoised'] : null,
                default => null,
            }
        );

        return $product;
    }

    private function proceed(): \Closure
    {
        return static fn() => ['from core'];
    }

    public function testUsesCoresOwnMemoWhenItIsAlreadySet(): void
    {
        $plugin = $this->plugin([]);
        $this->products->expects($this->never())->method('documents');

        $result = $plugin->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v1' => 1], true)
        );

        $this->assertSame(['memoised'], $result);
    }

    public function testFallsBackWhenSpecificAttributesAreRequested(): void
    {
        // A filtered request is not the listing path; core handles it.
        $result = $this->plugin([])->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v1' => 1]),
            [42]
        );

        $this->assertSame(['from core'], $result);
    }

    public function testFallsBackWithoutADocument(): void
    {
        $result = $this->plugin([])->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(null)
        );

        $this->assertSame(['from core'], $result);
    }

    public function testFallsBackWhenAnyListedVariantHasNoDocument(): void
    {
        // A configurable rendering a short option list is worse than a slow one.
        $result = $this->plugin([1 => []])->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v1' => 1, 'v2' => 2])
        );

        $this->assertSame(['from core'], $result);
    }

    public function testChildrenComeBackInAscendingIdOrder(): void
    {
        // Replaces the ksort the old VariantRepository did; the feed's key order is not id order.
        $plugin = $this->plugin([3 => [], 1 => [], 2 => []]);

        $children = $plugin->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v3' => 3, 'v1' => 1, 'v2' => 2])
        );

        $this->assertSame([1, 2, 3], array_map(static fn(Product $c) => $c->getId(), $children));
    }

    public function testARemovedVariantLinkIsNotExpected(): void
    {
        $plugin = $this->plugin([1 => []]);

        $children = $plugin->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v1' => 1, 'v2' => null])
        );

        $this->assertCount(1, $children, 'a null link is a removed variant, not a missing document');
    }

    public function testChildEntityIdIsWrittenAsAString(): void
    {
        // getJsonConfig() puts these straight into JSON. An int renders 1797 where core
        // renders "1797" — 1,440 bytes of difference on a twelve-card page.
        $plugin = $this->plugin([7 => []]);

        $plugin->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v7' => 7])
        );

        $this->assertContains(['entity_id', '7'], $this->written[7]);
    }

    public function testTierPriceIsEmptiedOnlyWhenTheDocumentCarriesNone(): void
    {
        $plugin = $this->plugin([7 => ['prices' => [['group' => '0', 'regular' => 10.0]]]]);

        $plugin->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v7' => 7])
        );

        $this->assertContains(['tier_price', []], $this->written[7]);
    }

    public function testTierPriceIsLeftToCoreWhenTheDocumentHasSome(): void
    {
        // The feed's tier price shape lacks the website and group fields Magento's structure
        // carries, so a product that genuinely has them is left alone rather than rebuilt.
        $plugin = $this->plugin([
            7 => ['prices' => [['group' => '0', 'regular' => 10.0, 'tierPrices' => [['qty' => 2]]]]],
        ]);

        $plugin->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v7' => 7])
        );

        $written = array_column($this->written[7], 0);
        $this->assertNotContains('tier_price', $written);
    }

    public function testCatalogRulePriceIsSetToNullWhenNoRuleApplies(): void
    {
        // Null is a real answer, and setting it satisfies CatalogRulePrice's hasData() check,
        // which is what skips the query.
        $plugin = $this->plugin([7 => ['prices' => [['group' => '0', 'regular' => 10.0]]]]);

        $plugin->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v7' => 7])
        );

        $this->assertContains(['catalog_rule_price', null], $this->written[7]);
    }

    public function testCatalogRulePriceComesFromTheTaggedDiscount(): void
    {
        $plugin = $this->plugin([
            7 => ['prices' => [[
                'group' => '0',
                'regular' => 10.0,
                'discounts' => [['code' => 'special_price', 'price' => 9.0], ['code' => 'catalog_rule', 'price' => 8.0]],
            ]]],
        ]);

        $plugin->aroundGetUsedProducts(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->parent(['v7' => 7])
        );

        $this->assertContains(['catalog_rule_price', 8.0], $this->written[7]);
    }
}
