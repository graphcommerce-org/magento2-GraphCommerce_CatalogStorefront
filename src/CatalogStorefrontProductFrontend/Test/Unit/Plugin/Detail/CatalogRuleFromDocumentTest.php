<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Detail\CatalogRuleFromDocument;
use Magento\Catalog\Model\Product;
use Magento\CatalogRule\Observer\ProcessFrontFinalPriceObserver;
use Magento\Customer\Model\Session;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CatalogRuleFromDocumentTest extends TestCase
{
    #[DataProvider('prices')]
    public function testRulePriceUsesDocument(array $prices, ?float $expected, bool $customDate): void
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $product->setData(['final_price' => 90.0, ProductDocumentsInterface::DOCUMENT_KEY => ['prices' => $prices]]);
        $session = $this->createStub(Session::class);
        $session->method('getCustomerGroupId')->willReturn(2);
        $plugin = new CatalogRuleFromDocument(new ProductPrice(), $session, $this->createStub(StoreManagerInterface::class));
        $observer = new Observer(['event' => new Event(['product' => $product])]);
        if ($customDate) {
            $observer->setData('date', '2026-01-01');
        }
        if ($expected === null) {
            $this->expectException(DocumentReadException::class);
        }
        $plugin->aroundExecute($this->createStub(ProcessFrontFinalPriceObserver::class), static function () { self::fail('The core rule reader was called.'); }, $observer);
        self::assertSame($expected, $product->getData('final_price'));
    }

    public static function prices(): iterable
    {
        yield 'rule price' => [[['group' => 'all', 'regular' => 100, 'discounts' => [['code' => 'catalog_rule', 'price' => 70]]]], 70.0, false];
        yield 'group rule' => [[['group' => 'all', 'regular' => 100, 'discounts' => [['code' => 'catalog_rule', 'price' => 70]]], ['group' => '2', 'regular' => 100, 'discounts' => [['code' => 'catalog_rule', 'price' => 60]]]], 60.0, false];
        yield 'special price retains lower price' => [[['group' => 'all', 'regular' => 100, 'discounts' => [['code' => 'catalog_rule', 'price' => 95]]]], 90.0, false];
        yield 'missing price' => [[], null, false];
        yield 'custom date' => [[['group' => 'all', 'regular' => 100]], null, true];
    }
}
