<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontQuoteGraphQl\Test\Unit\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use Psr\Log\LoggerInterface;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Query\SelectedProductFields;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontQuoteGraphQl\Plugin\Resolver\CartItemProductsFromDocuments;
use Magento\Catalog\Model\Product;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class CartItemProductsFromDocumentsTest extends TestCase
{
    private const PRICES = ['price' => ['value' => 34.0], 'row_total' => ['value' => 68.0]];

    private ContextInterface $context;
    private ResolveInfo $info;
    private HydrationInterface $hydration;
    private Strict $strict;

    protected function setUp(): void
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $extension = $this->createStub(ContextExtensionInterface::class);
        $extension->method('getStore')->willReturn($store);
        $this->context = $this->createStub(ContextInterface::class);
        $this->context->method('getExtensionAttributes')->willReturn($extension);
        $this->info = $this->createStub(ResolveInfo::class);
        $this->info->method('getFieldSelection')->willReturn(['product' => ['sku' => true, 'small_image' => true]]);
        $this->hydration = $this->createMock(HydrationInterface::class);
        $this->strict = new Strict($this->createStub(StorefrontKey::class), $this->createStub(LoggerInterface::class));
    }

    public function testTheDocumentFieldsLandOnTheItemProductAndThePricesStay(): void
    {
        $model = $this->product(1);
        $model->expects(self::once())->method('setData')->with(HydrationInterface::DOCUMENT_KEY, ['productId' => 1]);
        $model->method('getData')->with(PrefillerInterface::KEY)->willReturn(['small_image' => ['url' => 'a.jpg']]);
        $this->hydration->expects(self::once())->method('documents')->with('default', [1])->willReturn([1 => ['productId' => 1]]);
        $this->hydration->expects(self::once())
            ->method('prefill')
            ->with(self::anything(), $this->context, [1 => $model], [1 => ['productId' => 1]], ['sku', 'small_image']);

        $result = $this->plugin()->afterResolve(
            $this->createStub(ResolverInterface::class),
            [['uid' => 'MQ==', 'quantity' => 2.0, 'prices' => self::PRICES, 'product' => ['sku' => '24-MB01', 'model' => $model]]],
            $this->createStub(Field::class),
            $this->context,
            $this->info
        );

        self::assertSame(['small_image' => ['url' => 'a.jpg']], $result[0]['product'][PrefillerInterface::KEY]);
        self::assertSame('24-MB01', $result[0]['product']['sku']);
        self::assertSame(self::PRICES, $result[0]['prices']);
        self::assertSame(2.0, $result[0]['quantity']);
    }

    public function testAPaginatedResultKeepsItsShape(): void
    {
        $model = $this->product(1);
        $model->method('getData')->willReturn(['sku' => '24-MB01']);
        $this->hydration->method('documents')->willReturn([1 => ['productId' => 1]]);

        $result = $this->plugin()->afterResolve(
            $this->createStub(ResolverInterface::class),
            ['items' => [['product' => ['model' => $model]]], 'total_count' => 1],
            $this->createStub(Field::class),
            $this->context,
            $this->info
        );

        self::assertSame(1, $result['total_count']);
        self::assertArrayHasKey(PrefillerInterface::KEY, $result['items'][0]['product']);
    }

    public function testAMissingProductDocumentFails(): void
    {
        $model = $this->product(46);
        $this->hydration->method('documents')->willReturn([]);
        $this->hydration->expects(self::never())->method('prefill');

        $items = [['quantity' => 1.0, 'prices' => self::PRICES, 'product' => ['sku' => '24-WG080', 'model' => $model]]];
        $this->expectException(DocumentReadException::class);
        $this->plugin()->afterResolve(
            $this->createStub(ResolverInterface::class),
            $items,
            $this->createStub(Field::class),
            $this->context,
            $this->info
        );
    }

    public function testTheCorePathIsLeftAlone(): void
    {
        $mode = $this->createStub(Mode::class);
        $mode->method('documents')->willReturn(false);
        $this->hydration->expects(self::never())->method('documents');
        $plugin = new CartItemProductsFromDocuments($mode, $this->hydration, new SelectedProductFields(), $this->strict);

        $items = [['product' => ['model' => $this->product(1)]]];
        self::assertSame($items, $plugin->afterResolve(
            $this->createStub(ResolverInterface::class),
            $items,
            $this->createStub(Field::class),
            $this->context,
            $this->info
        ));
    }

    private function plugin(): CartItemProductsFromDocuments
    {
        $mode = $this->createStub(Mode::class);
        $mode->method('documents')->willReturn(true);

        return new CartItemProductsFromDocuments($mode, $this->hydration, new SelectedProductFields(), $this->strict);
    }

    private function product(int $id): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
