<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSalesGraphQl\Test\Unit\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Query\SelectedProductFields;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\ItemProducts;
use GraphCommerce\CatalogStorefrontSalesGraphQl\Plugin\Resolver\OrderItemProducts;
use GraphCommerce\CatalogStorefrontSalesGraphQl\Plugin\Resolver\ProductFromDocument;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\SalesGraphQl\Model\Resolver\OrderItems;
use Magento\SalesGraphQl\Model\Resolver\ProductResolver;
use PHPUnit\Framework\TestCase;

class OrderItemProductsTest extends TestCase
{
    public function testTheOrderRegistersTheProductsOfEveryItem(): void
    {
        $items = $this->createMock(ItemProducts::class);
        $items->method('enabled')->willReturn(true);
        $items->expects(self::once())->method('expect')->with([1, 46], ['sku', 'name'], null);
        $order = $this->createStub(OrderInterface::class);
        $order->method('getItems')->willReturn([$this->orderItem(1), $this->orderItem(46)]);
        $info = $this->createStub(ResolveInfo::class);
        $info->method('getFieldSelection')->willReturn(['product' => ['sku' => true, 'name' => true]]);

        $plugin = new OrderItemProducts($items, new SelectedProductFields());
        self::assertSame('untouched', $plugin->afterResolve(
            $this->createStub(OrderItems::class),
            'untouched',
            $this->createStub(Field::class),
            null,
            $info,
            ['model' => $order]
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
            ['model' => $this->orderItem(1)]
        ));
        self::assertSame(['sku' => 'from core'], $plugin->aroundResolve(
            $subject,
            $proceed,
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
            ['model' => $this->orderItem(46)]
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
            ['model' => $this->orderItem(1)]
        ));
    }

    private function orderItem(int $productId): OrderItemInterface
    {
        $item = $this->createStub(OrderItemInterface::class);
        $item->method('getProductId')->willReturn($productId);

        return $item;
    }
}
