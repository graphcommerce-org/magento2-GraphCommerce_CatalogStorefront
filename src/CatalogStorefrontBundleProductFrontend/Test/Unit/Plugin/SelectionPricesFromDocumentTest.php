<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProductFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read\Options;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read\Selections;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Plugin\SelectionPricesFromDocument;
use Magento\Bundle\Model\Option;
use Magento\Bundle\Model\Product\Type;
use Magento\Bundle\Pricing\Adjustment\SelectionPriceListProviderInterface;
use Magento\Bundle\Pricing\Price\BundleSelectionFactory;
use Magento\Bundle\Pricing\Price\BundleSelectionPrice;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\Pricing\Amount\AmountInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SelectionPricesFromDocumentTest extends TestCase
{
    #[DataProvider('cases')]
    public function testPriceCandidatesFollowCore(bool $fixed, bool $min, bool $required, bool $multi, bool $regular, bool $missingStock, array $expected): void
    {
        $parent = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['getTypeInstance', 'isSalable', 'getPrice'])->getMock();
        $parent->setData([ProductDocumentsInterface::DOCUMENT_KEY => [], 'price_type' => (int)$fixed, 'store_id' => 1]);
        $parent->method('isSalable')->willReturn(true);
        $parent->method('getPrice')->willReturn(100.0);
        $type = $this->createStub(Type::class);
        $parent->method('getTypeInstance')->willReturn($type);
        $options = (new \ReflectionClass(Options::class))->newInstanceWithoutConstructor();
        $option = (new \ReflectionClass(Option::class))->newInstanceWithoutConstructor();
        $option->setIdFieldName('option_id');
        $option->setData(['option_id' => 1, 'required' => $required, 'type' => $multi ? 'checkbox' : 'radio']);
        $options->addItem($option);
        $type->method('getOptionsCollection')->willReturn($options);
        $selections = (new \ReflectionClass(Selections::class))->newInstanceWithoutConstructor();
        $selections->setRowIdFieldName('selection_id');
        $priceIds = [];
        $factory = $this->createStub(BundleSelectionFactory::class);
        $factory->method('create')->willReturnCallback(function ($bundle, $child, $qty) use (&$priceIds) {
            $price = $this->createStub(BundleSelectionPrice::class);
            $price->method('getValue')->willReturn(10.0);
            $price->method('getQuantity')->willReturn($qty);
            $amount = $this->createStub(AmountInterface::class);
            $amount->method('getValue')->willReturn(10.0);
            $price->method('getAmount')->willReturn($amount);
            $priceIds[spl_object_id($price)] = (int)$child->getSelectionId();
            return $price;
        });
        foreach ([[1, 10, 8, 1, 10], [2, 20, 3, 2, 10], [3, 1, 1, 20, 2]] as [$id, $regularPrice, $minimal, $qty, $stockQty]) {
            $child = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['isSalable', 'getPrice'])->getMock();
            $child->method('isSalable')->willReturn(true);
            $child->method('getPrice')->willReturn((float)$regularPrice);
            $child->setData([
                'selection_id' => $id, 'status' => 1, 'minimal_price' => $minimal,
                'selection_qty' => $qty, 'selection_can_change_qty' => false,
                'selection_price_type' => $id === 2 ? 1 : 0, 'selection_price_value' => $regularPrice,
                ProductDocumentsInterface::DOCUMENT_KEY => ['stock' => $missingStock ? [] : [
                    'manageStock' => true, 'backorders' => false, 'minSaleQty' => null,
                    'itemInStock' => true, 'itemQty' => $stockQty,
                ]],
            ]);
            $selections->addItem($child);
        }
        $type->method('getSelectionsCollection')->willReturn($selections);
        $stockConfig = $this->createStub(StockConfigurationInterface::class);
        $stockConfig->method('isShowOutOfStock')->willReturn(false);
        $plugin = new SelectionPricesFromDocument($factory, $stockConfig);
        if ($missingStock) {
            $this->expectException(DocumentReadException::class);
        }
        $result = $plugin->aroundGetPriceList($this->createStub(SelectionPriceListProviderInterface::class), static function () { self::fail('The core price reader was called.'); }, $parent, $min, $regular);
        self::assertSame($expected, array_map(static fn($price) => $priceIds[spl_object_id($price)], $result));
    }

    public function testAnUnsalableBundleLeavesUnsalableSelectionsOutOfItsDynamicMinimum(): void
    {
        // Core's selection collection has no out of stock selection when the store hides them,
        // also when a required option has no salable selection and the bundle itself is not salable.
        $parent = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['getTypeInstance', 'isSalable'])->getMock();
        $parent->setData([ProductDocumentsInterface::DOCUMENT_KEY => [], 'price_type' => 0, 'store_id' => 1]);
        $parent->method('isSalable')->willReturn(false);
        $type = $this->createStub(Type::class);
        $parent->method('getTypeInstance')->willReturn($type);
        $options = (new \ReflectionClass(Options::class))->newInstanceWithoutConstructor();
        $option = (new \ReflectionClass(Option::class))->newInstanceWithoutConstructor();
        $option->setIdFieldName('option_id');
        $option->setData(['option_id' => 1, 'required' => true, 'type' => 'radio']);
        $options->addItem($option);
        $type->method('getOptionsCollection')->willReturn($options);
        $selections = (new \ReflectionClass(Selections::class))->newInstanceWithoutConstructor();
        $selections->setRowIdFieldName('selection_id');
        foreach ([[1, 5, false], [2, 9, true]] as [$id, $minimal, $salable]) {
            $child = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['isSalable'])->getMock();
            $child->method('isSalable')->willReturn($salable);
            $child->setData(['selection_id' => $id, 'status' => 1, 'minimal_price' => $minimal, 'selection_qty' => 1]);
            $selections->addItem($child);
        }
        $type->method('getSelectionsCollection')->willReturn($selections);
        $created = [];
        $factory = $this->createStub(BundleSelectionFactory::class);
        $factory->method('create')->willReturnCallback(function ($bundle, $child) use (&$created) {
            $created[] = (int)$child->getSelectionId();

            return $this->createStub(BundleSelectionPrice::class);
        });
        $stockConfig = $this->createStub(StockConfigurationInterface::class);
        $stockConfig->method('isShowOutOfStock')->willReturn(false);

        (new SelectionPricesFromDocument($factory, $stockConfig))->aroundGetPriceList(
            $this->createStub(SelectionPriceListProviderInterface::class),
            static function () { self::fail('The core price reader was called.'); },
            $parent,
            true,
            false
        );

        self::assertSame([2], $created);
    }

    public static function cases(): iterable
    {
        yield 'dynamic final minimum uses index price and quantity' => [false, true, true, false, false, false, [2]];
        yield 'dynamic regular minimum excludes short stock' => [false, true, true, false, true, false, [1]];
        yield 'fixed minimum' => [true, true, true, false, false, false, [1]];
        yield 'fixed maximum' => [true, false, true, false, false, false, [2]];
        yield 'multi maximum includes every selection' => [false, false, false, true, false, false, [1, 2, 3]];
        yield 'optional fixed minimum' => [true, true, false, false, false, false, []];
        yield 'optional dynamic minimum' => [false, true, false, false, false, false, [2]];
        yield 'missing stock stops the read' => [false, true, true, false, false, true, []];
    }
}
