<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProductFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read\Options;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read\OptionsFactory;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read\Selections;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read\SelectionsFactory;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Plugin\OptionsFromDocument;
use Magento\Bundle\Model\Option;
use Magento\Bundle\Model\OptionFactory;
use Magento\Bundle\Model\Product\Type;
use Magento\Catalog\Model\Product;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OptionsFromDocumentTest extends TestCase
{
    #[DataProvider('documents')]
    public function testOptionsAndSelectionsUseDocuments(bool $missing, bool $invalidUid): void
    {
        $parent = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $parent->setIdFieldName('entity_id');
        $value = ['sku' => 'child', 'id' => base64_encode($invalidUid ? 'bundle/9/7/2' : 'bundle/1/7/2'), 'sortOrder' => 2, 'qty' => 2, 'qtyMutability' => true, 'priceType' => 'percent', 'price' => 15, 'isDefault' => true];
        $parent->setData(['entity_id' => '42', 'store_id' => 1, ProductDocumentsInterface::DOCUMENT_KEY => [
            'bundleChildIds' => [4], 'optionsV2' => [
                ['id' => '2', 'label' => 'Second', 'type' => 'bundle', 'renderType' => 'checkbox', 'sortOrder' => 2, 'required' => false, 'values' => [array_replace($value, ['id' => base64_encode('bundle/2/8/1'), 'qty' => 1])]],
                ['id' => '1', 'label' => 'First', 'type' => 'bundle', 'renderType' => 'select', 'sortOrder' => 1, 'required' => true, 'values' => [$value]],
            ],
        ]]);
        $child = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['getSku'])->getMock();
        $child->setIdFieldName('entity_id');
        $child->setData(['entity_id' => '4', 'required_options' => false]);
        $child->method('getSku')->willReturn('child');
        $products = $this->createMock(ProductDocumentsInterface::class);
        $products->expects(self::once())->method('documents')->with('default', [4])->willReturn([]);
        $products->method('build')->willReturn($missing ? [] : [4 => $child]);
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $optionFactory = $this->createStub(OptionFactory::class);
        $optionFactory->method('create')->willReturnCallback(static function () {
            $option = (new \ReflectionClass(Option::class))->newInstanceWithoutConstructor();
            $option->setIdFieldName('option_id');
            return $option;
        });
        $optionsFactory = $this->createStub(OptionsFactory::class);
        $optionsFactory->method('create')->willReturn((new \ReflectionClass(Options::class))->newInstanceWithoutConstructor());
        $selectionsFactory = $this->createStub(SelectionsFactory::class);
        $selectionsFactory->method('create')->willReturnCallback(static function () {
            $selections = (new \ReflectionClass(Selections::class))->newInstanceWithoutConstructor();
            $selections->setRowIdFieldName('selection_id');
            return $selections;
        });
        $uid = $this->createStub(Uid::class);
        $uid->method('decode')->willReturnCallback('base64_decode');
        $plugin = new OptionsFromDocument($products, $stores, $optionFactory, $optionsFactory, $selectionsFactory, $uid);
        $type = $this->createStub(Type::class);
        $core = static function () { self::fail('The core option reader was called.'); };
        $options = $plugin->aroundGetOptionsCollection($type, $core, $parent);
        self::assertSame([1, 2], $options->getAllIds());
        self::assertSame('First', $options->getFirstItem()->getTitle());
        self::assertSame($options, $plugin->aroundGetOptionsCollection($type, $core, $parent));
        if ($missing || $invalidUid) {
            $this->expectException(DocumentReadException::class);
        }
        $first = $plugin->aroundGetSelectionsCollection($type, $core, [1], $parent);
        self::assertSame([7], array_keys($first->getItems()));
        self::assertSame('1', $first->getFirstItem()->getData('selection_price_type'));
        self::assertSame(2.0, $first->getFirstItem()->getData('selection_qty'));
        self::assertTrue($first->getFlag('tier_price_added'));
        $second = $plugin->aroundGetSelectionsCollection($type, $core, [2], $parent);
        self::assertSame([8], array_keys($second->getItems()));
        self::assertNotSame($first->getFirstItem(), $second->getFirstItem());
        self::assertNull($child->getData('selection_id'));
        self::assertSame(2.0, $first->getFirstItem()->getData('selection_qty'));
    }

    public static function documents(): iterable
    {
        yield 'complete' => [false, false];
        yield 'missing child' => [true, false];
        yield 'invalid selection option' => [false, true];
    }
}
