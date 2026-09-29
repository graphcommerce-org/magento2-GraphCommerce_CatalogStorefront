<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGroupedProductFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontGroupedProductFrontend\Plugin\QuantityFromDocument;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\GroupedProduct\ViewModel\ValidateQuantity;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantityFromDocumentTest extends TestCase
{
    #[DataProvider('stock')]
    public function testQuantityUsesDocumentAndStoreConfig(array $stock, ?array $expected): void
    {
        $parent = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['getTypeInstance'])->getMock();
        $parent->setData([ProductDocumentsInterface::DOCUMENT_KEY => [], 'store_id' => 1]);
        $child = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $child->method('getId')->willReturn('4');
        $child->setData(ProductDocumentsInterface::DOCUMENT_KEY, ['stock' => $stock]);
        $type = $this->createStub(Grouped::class);
        $type->method('getAssociatedProducts')->willReturn([$child]);
        $parent->method('getTypeInstance')->willReturn($type);
        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturn($parent);
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getId')->willReturn(1);
        $store->method('getWebsiteId')->willReturn(1);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $config = $this->createStub(StockConfigurationInterface::class);
        $config->method('getMinSaleQty')->willReturn(2);
        $config->method('getMaxSaleQty')->willReturn(100);
        $config->method('getEnableQtyIncrements')->willReturn(true);
        $config->method('getQtyIncrements')->willReturn(2.5);
        $plugin = new QuantityFromDocument($registry, $stores, $config, $this->createStub(Session::class), new Json());
        if ($expected === null) {
            $this->expectException(DocumentReadException::class);
        }
        $result = $plugin->aroundGetQuantityValidators($this->createStub(ValidateQuantity::class), static function () { self::fail('The core stock reader was called.'); }, 4, 1);
        self::assertSame(['validate-grouped-qty' => '#super-product-table', 'validate-item-quantity' => $expected], json_decode($result, true));
    }

    public static function stock(): iterable
    {
        yield 'configured decimal quantities' => [['minSaleQty' => null, 'maxSaleQty' => null, 'qtyIncrements' => null, 'enableQtyIncrements' => null, 'isQtyDecimal' => true], ['minAllowed' => 2, 'maxAllowed' => 100, 'qtyIncrements' => 2.5]];
        yield 'product integer quantities' => [['minSaleQty' => 3, 'maxSaleQty' => 9, 'qtyIncrements' => 3.8, 'enableQtyIncrements' => true, 'isQtyDecimal' => false], ['minAllowed' => 3, 'maxAllowed' => 9, 'qtyIncrements' => 3]];
        yield 'increments disabled' => [['minSaleQty' => 1, 'maxSaleQty' => 0, 'qtyIncrements' => 5, 'enableQtyIncrements' => false, 'isQtyDecimal' => false], ['minAllowed' => 1]];
        yield 'missing stock' => [[], null];
    }
}
