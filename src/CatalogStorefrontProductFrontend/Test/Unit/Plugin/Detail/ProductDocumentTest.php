<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Detail;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Detail\ProductDocument;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Console\Request as ConsoleRequest;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductDocumentTest extends TestCase
{
    private const PRODUCT_ID = 42;

    private ProductDocumentsInterface&MockObject $products;

    private ProductRepositoryInterface&MockObject $repository;

    private bool $proceeded = false;

    private function plugin(
        bool $serveDetail = true,
        string $action = 'catalog_product_view',
        int $requestedId = self::PRODUCT_ID,
        ?RequestInterface $request = null,
        array $supportedOptionTypes = [],
    ): ProductDocument {
        $this->products = $this->createMock(ProductDocumentsInterface::class);
        $this->repository = $this->createMock(ProductRepositoryInterface::class);

        $mode = $this->createMock(Mode::class);
        $mode->method('detail')->willReturn($serveDetail);

        if ($request === null) {
            $http = $this->createMock(HttpRequest::class);
            $http->method('getFullActionName')->willReturn($action);
            $http->method('getParam')->willReturn((string)$requestedId);
            $request = $http;
        }

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new ProductDocument(
            $this->products,
            $mode,
            $storeManager,
            $request,
            $this->createMock(LoggerInterface::class),
            $supportedOptionTypes,
        );
    }

    /**
     * The database load, which must not run when a document serves the page.
     */
    private function proceed(): \Closure
    {
        return function () {
            $this->proceeded = true;

            return $this->createMock(Product::class);
        };
    }

    private function serves(int $id = self::PRODUCT_ID): Product&MockObject
    {
        $model = $this->createMock(Product::class);
        $model->method('getId')->willReturn($id);
        $this->products->method('documents')->willReturn([$id => ['sku' => 'WT09']]);
        $this->products->method('build')->willReturn([$id => $model]);

        return $model;
    }

    public function testTheDocumentReplacesTheLoad(): void
    {
        $plugin = $this->plugin();
        $model = $this->serves();

        $result = $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);

        $this->assertSame($model, $result);
        $this->assertFalse($this->proceeded, 'the database load is what this avoids');
    }

    public function testThePageIsBuiltOncePerRequest(): void
    {
        // The repository keeps its own instance per id, and this answers before it.
        $plugin = $this->plugin();
        $this->serves();
        $this->products->expects($this->once())->method('documents');

        $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);
        $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);
    }

    public function testResetDropsTheBuiltProduct(): void
    {
        // The plugin outlives a request under an application server.
        $plugin = $this->plugin();
        $this->serves();
        $this->products->expects($this->exactly(2))->method('documents');

        $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);
        $plugin->_resetState();
        $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);
    }

    public function testAnotherProductOnTheSameRequestLoads(): void
    {
        // A detail page loads related and upsell products through the same repository.
        $plugin = $this->plugin();
        $this->products->expects($this->never())->method('documents');

        $plugin->aroundGetById($this->repository, $this->proceed(), 7);

        $this->assertTrue($this->proceeded);
    }

    public function testAnotherPageLoads(): void
    {
        $plugin = $this->plugin(true, 'checkout_cart_index');
        $this->products->expects($this->never())->method('documents');

        $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);

        $this->assertTrue($this->proceeded);
    }

    public function testAConsoleRequestLoads(): void
    {
        // An indexer, a console command and a queue consumer share the repository, and their
        // request has no action at all.
        $plugin = $this->plugin(true, '', 0, $this->createMock(ConsoleRequest::class));
        $this->products->expects($this->never())->method('documents');

        $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);

        $this->assertTrue($this->proceeded);
    }

    public function testEditModeLoads(): void
    {
        // The admin edits the database's product, never a document's.
        $plugin = $this->plugin();
        $this->products->expects($this->never())->method('documents');

        $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID, true);

        $this->assertTrue($this->proceeded);
    }

    public function testAForcedReloadLoads(): void
    {
        $plugin = $this->plugin();
        $this->products->expects($this->never())->method('documents');

        $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID, false, null, true);

        $this->assertTrue($this->proceeded);
    }

    public function testTheSettingOffLoads(): void
    {
        $plugin = $this->plugin(false);
        $this->products->expects($this->never())->method('documents');

        $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);

        $this->assertTrue($this->proceeded);
    }

    public function testUnsupportedCustomOptionsFail(): void
    {
        $plugin = $this->plugin();
        $this->products->method('documents')
            ->willReturn([self::PRODUCT_ID => ['optionsV2' => [['type' => 'custom', 'id' => 'engraving']]]]);
        $this->products->expects($this->never())->method('build');

        $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        try {
            $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);
        } finally {
            self::assertFalse($this->proceeded);
        }
    }

    #[DataProvider('compositeDocuments')]
    public function testCompositeDetailRequiresItsReaderAndFields(array $document, array $types, bool $supported): void
    {
        $plugin = $this->plugin(supportedOptionTypes: $types);
        $model = $this->createMock(Product::class);
        $this->products->method('documents')->willReturn([self::PRODUCT_ID => $document]);
        if ($supported) {
            $this->products->expects(self::once())->method('build')->willReturn([self::PRODUCT_ID => $model]);
        } else {
            $this->products->expects(self::never())->method('build');
            $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        }
        try {
            self::assertSame($model, $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID));
        } finally {
            self::assertFalse($this->proceeded);
        }
    }

    public static function compositeDocuments(): iterable
    {
        foreach (['bundle', 'bundle_fixed', 'grouped'] as $type) {
            $option = $type === 'bundle_fixed' ? 'bundle' : $type;
            yield $type => [['type' => $type, 'hasOptions' => true, 'requiredOptions' => false, 'optionsV2' => [['type' => $option]]], [$option => true], true];
        }
        yield 'missing reader with empty options' => [['type' => 'bundle', 'hasOptions' => false, 'requiredOptions' => false], [], false];
        yield 'missing option flags' => [['type' => 'bundle'], ['bundle' => true], false];
        yield 'shopper input' => [['shopperInputOptions' => [['type' => 'text']]], [], false];
    }


    public function testAMissingDocumentFails(): void
    {
        $plugin = $this->plugin();
        $this->products->method('documents')->willReturn([]);
        $this->products->method('build')->willReturn([]);

        $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        try {
            $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);
        } finally {
            self::assertFalse($this->proceeded);
        }
    }

    public function testAFailedFetchStopsTheRead(): void
    {
        $plugin = $this->plugin();
        $this->products->method('documents')->willThrowException(new \RuntimeException('no alive nodes'));

        $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        try {
            $plugin->aroundGetById($this->repository, $this->proceed(), self::PRODUCT_ID);
        } finally {
            self::assertFalse($this->proceeded);
        }
    }
}
