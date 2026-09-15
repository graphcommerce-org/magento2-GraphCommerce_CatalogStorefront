<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\ItemProducts;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use Magento\Catalog\Model\Product;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ItemProductsTest extends TestCase
{
    private HydrationInterface $hydration;
    private ContextInterface $context;
    private Strict $strict;

    protected function setUp(): void
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $extension = $this->createStub(ContextExtensionInterface::class);
        $extension->method('getStore')->willReturn($store);
        $this->context = $this->createStub(ContextInterface::class);
        $this->context->method('getExtensionAttributes')->willReturn($extension);
        $this->hydration = $this->createMock(HydrationInterface::class);
        $this->strict = $this->createMock(Strict::class);
    }

    public function testTheWholeBatchIsFetchedOnceAndAProductWithoutADocumentHasNoValue(): void
    {
        $mode = $this->createStub(Mode::class);
        $mode->method('documents')->willReturn(true);
        $this->hydration->expects(self::once())
            ->method('documents')
            ->with('default', [1, 46])
            ->willReturn([1 => ['productId' => 1]]);
        $model = $this->createStub(Product::class);
        $model->method('getData')->willReturn(['sku' => '24-MB01']);
        $this->hydration->expects(self::once())
            ->method('models')
            ->with(self::anything(), $this->context, [1 => ['productId' => 1]], ['sku', 'name'])
            ->willReturn([1 => $model]);

        $products = new ItemProducts($mode, $this->hydration, $this->createStub(StoreManagerInterface::class), $this->strict);
        self::assertTrue($products->enabled());
        $products->expect([1, 46], ['sku'], $this->context);
        $products->expect([1], ['name'], $this->context);

        self::assertSame(['sku' => '24-MB01', 'model' => $model], $products->value(1));
        self::assertNull($products->value(46));
        self::assertNull($products->value(99));
    }

    public function testAFailedFetchIsReportedAndLeavesEveryItemToCore(): void
    {
        $mode = $this->createStub(Mode::class);
        $mode->method('documents')->willReturn(true);
        $this->hydration->method('documents')->willThrowException(new \RuntimeException('cluster down'));
        $this->strict->expects(self::once())->method('exception');

        $products = new ItemProducts($mode, $this->hydration, $this->createStub(StoreManagerInterface::class), $this->strict);
        $products->expect([1], [], $this->context);

        self::assertNull($products->value(1));
    }
}
