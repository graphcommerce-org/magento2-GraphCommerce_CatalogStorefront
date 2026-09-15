<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWishlistGraphQl\Test\Unit\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Query\SelectedProductFields;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\ItemProducts;
use GraphCommerce\CatalogStorefrontWishlistGraphQl\Plugin\Resolver\ProductFromDocument;
use GraphCommerce\CatalogStorefrontWishlistGraphQl\Plugin\Resolver\WishlistItemProducts;
use Magento\Catalog\Model\Product;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\WishlistGraphQl\Model\Resolver\ProductResolver;
use PHPUnit\Framework\TestCase;

class WishlistItemProductsTest extends TestCase
{
    public function testTheItemsRegisterTheirProductsAndTheItemProductComesFromTheBatch(): void
    {
        $items = $this->createMock(ItemProducts::class);
        $items->method('enabled')->willReturn(true);
        $items->expects(self::once())->method('expect')->with([1, 46], ['sku'], null);
        $info = $this->createStub(ResolveInfo::class);
        $info->method('getFieldSelection')->willReturn(['items' => ['product' => ['sku' => true]]]);

        $result = ['items' => [['model' => $this->product(1)], ['model' => $this->product(46)]]];
        $plugin = new WishlistItemProducts($items, new SelectedProductFields());
        self::assertSame($result, $plugin->afterResolve(
            $this->createStub(ResolverInterface::class),
            $result,
            $this->createStub(Field::class),
            null,
            $info
        ));
    }

    public function testTheProductOfAnItemIsServedAndAMissingDocumentGoesToCore(): void
    {
        $items = $this->createMock(ItemProducts::class);
        $items->method('enabled')->willReturn(true);
        $items->method('value')->willReturnMap([[1, ['sku' => '24-MB01']], [46, null]]);
        $strict = $this->createMock(Strict::class);
        $strict->expects(self::once())->method('fallback')->with(ProductFromDocument::class, 'no document for product 46');
        $plugin = new ProductFromDocument($items, $strict);
        $subject = $this->createStub(ProductResolver::class);
        $proceed = static fn(): array => ['sku' => 'from core'];

        self::assertSame(['sku' => '24-MB01'], $plugin->aroundResolve(
            $subject,
            $proceed,
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
            ['model' => $this->product(1)]
        ));
        self::assertSame(['sku' => 'from core'], $plugin->aroundResolve(
            $subject,
            $proceed,
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
            ['model' => $this->product(46)]
        ));
    }

    public function testTheCorePathIsLeftAlone(): void
    {
        $items = $this->createMock(ItemProducts::class);
        $items->method('enabled')->willReturn(false);
        $items->expects(self::never())->method('value');
        $plugin = new ProductFromDocument($items, $this->createMock(Strict::class));

        self::assertSame(['sku' => 'from core'], $plugin->aroundResolve(
            $this->createStub(ProductResolver::class),
            static fn(): array => ['sku' => 'from core'],
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
            ['model' => $this->product(1)]
        ));
    }

    private function product(int $id): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
