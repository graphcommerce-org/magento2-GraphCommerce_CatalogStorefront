<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Detail\PricesFromDocument;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PricesFromDocumentTest extends TestCase
{
    #[DataProvider('prices')]
    public function testPricesUseCustomerGroup(string $type, array $prices, ?array $expected): void
    {
        $session = $this->createStub(Session::class);
        $session->method('getCustomerGroupId')->willReturn(2);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $model = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $plugin = new PricesFromDocument(new ProductPrice(), $session);
        if ($expected === null) {
            $this->expectException(DocumentReadException::class);
        }
        $result = $plugin->afterBuild($this->createStub(ProductDocumentsInterface::class), [4 => $model], $store, [4 => ['type' => $type, 'prices' => $prices]]);
        self::assertSame($model, $result[4]);
        self::assertSame($expected[0], $model->getData('catalog_rule_price'));
        self::assertSame($expected[1], array_column($model->getData('tier_price'), 'website_price'));
        self::assertSame($expected[2], $model->getData('minimal_price'));
    }

    public static function prices(): iterable
    {
        $row = ['group' => 'all', 'regular' => 100.0, 'discounts' => [['code' => 'catalog_rule', 'price' => 80]], 'tierPrices' => [['qty' => 3, 'percentage' => 30]]];
        yield 'simple percent tier' => ['simple', [$row], [80.0, [70.0], 70.0]];
        yield 'bundle percent tier' => ['bundle_fixed', [$row], [80.0, [30.0], null]];
        yield 'group override' => ['simple', [$row, ['group' => '2', 'regular' => 100.0, 'discounts' => [['code' => 'catalog_rule', 'price' => 0]]]], [0.0, [], 0.0]];
        yield 'grouped range remains computed' => ['grouped', [$row], [80.0, [70.0], null]];
        yield 'missing simple price' => ['simple', [], null];
    }
}
