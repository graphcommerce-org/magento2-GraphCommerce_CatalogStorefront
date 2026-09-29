<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGroupedProductFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontGroupedProductFrontend\Plugin\AssociatedProductsFromDocument;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssociatedProductsFromDocumentTest extends TestCase
{
    #[DataProvider('cases')]
    public function testChildrenMatchStoreVisibilityAndLinkOrder(bool $showOutOfStock, bool $missing): void
    {
        $children = [];
        $links = [];
        foreach ([1, 2, 3, 4] as $id) {
            $child = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['isSalable', 'getSku'])->getMock();
            $child->setIdFieldName('entity_id');
            $child->setData(['entity_id' => (string)$id, 'sku' => 'child-' . $id, 'status' => $id === 3 ? 2 : 1, 'required_options' => $id === 4]);
            $child->method('getSku')->willReturn('child-' . $id);
            $child->method('isSalable')->willReturn($id !== 2);
            $children[$id] = $child;
            $links[] = ['sku' => 'child-' . $id, 'qty' => $id, 'sortOrder' => 5 - $id];
        }
        $parent = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $parent->setIdFieldName('entity_id');
        $parent->setData(['entity_id' => '10', 'store_id' => 1, ProductDocumentsInterface::DOCUMENT_KEY => ['groupedChildIds' => [1, 2, 3, 4], 'optionsV2' => [['type' => 'grouped', 'values' => $links]]]]);
        $products = $this->createMock(ProductDocumentsInterface::class);
        $products->expects(self::once())->method('documents')->with('default', [1, 2, 3, 4])->willReturn([]);
        $products->method('build')->willReturn($missing ? array_slice($children, 0, 3, true) : $children);
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $stock = $this->createStub(StockConfigurationInterface::class);
        $stock->method('isShowOutOfStock')->willReturn($showOutOfStock);
        $plugin = new AssociatedProductsFromDocument($products, $stores, $stock);
        $core = static function () { self::fail('The core child reader was called.'); };
        $type = $this->createStub(Grouped::class);
        if ($missing) {
            $this->expectException(DocumentReadException::class);
        }
        $result = $plugin->aroundGetAssociatedProducts($type, $core, $parent);
        self::assertSame($showOutOfStock ? ['2', '1'] : ['1'], array_map(static fn(Product $child) => $child->getId(), $result));
        self::assertSame($result, $plugin->aroundGetAssociatedProducts($type, $core, $parent));
        self::assertSame(1.0, $result[count($result) - 1]->getData('qty'));
        self::assertNull($children[1]->getData('qty'));
    }

    public static function cases(): iterable
    {
        yield 'visible stock' => [false, false];
        yield 'out of stock visible' => [true, false];
        yield 'missing child' => [false, true];
    }
}
