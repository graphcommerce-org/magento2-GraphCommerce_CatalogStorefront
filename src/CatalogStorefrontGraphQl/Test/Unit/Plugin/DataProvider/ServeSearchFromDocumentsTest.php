<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\DataProvider;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontGraphQl\Model\DocumentHydration;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\DataProvider\ServeSearchFromDocuments;
use Magento\Catalog\Api\Data\ProductSearchResultsInterfaceFactory;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\ProductSearch;
use Magento\Customer\Model\Session;
use Magento\Framework\Api\Search\DocumentInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ServeSearchFromDocumentsTest extends TestCase
{
    #[DataProvider('failures')]
    public function testDocumentFailuresStopTheCoreReader(bool $keyed, bool $storageFailure): void
    {
        $key = $this->createStub(StorefrontKey::class);
        $key->method('granted')->willReturn($keyed);
        $strict = new Strict($key, $this->createStub(LoggerInterface::class));
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $cause = new \RuntimeException('Storage unavailable.');
        $read = $storage->expects(self::once())->method('listing')->with('default', [42], null);
        if ($storageFailure) {
            $read->willThrowException($cause);
        } else {
            $read->willReturn([[], []]);
        }
        $products = $this->createStub(ProductDocumentsInterface::class);
        $products->method('build')->willReturn([]);
        $products->method('priceDataLoader')->willReturn(static fn() => []);
        $mode = $this->createStub(Mode::class);
        $mode->method('documents')->willReturn(true);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $factory = $this->createMock(ProductSearchResultsInterfaceFactory::class);
        $factory->expects(self::never())->method('create');
        $hydration = new DocumentHydration(
            $products, $storage, $mode, $factory, $stores,
            $this->createStub(Session::class), new ProductPrice(), $strict
        );
        $item = $this->createStub(DocumentInterface::class);
        $item->method('getId')->willReturn(42);
        $search = $this->createStub(SearchResultInterface::class);
        $search->method('getItems')->willReturn([$item]);
        $search->method('getTotalCount')->willReturn(1);
        $coreCalls = 0;
        $proceed = function () use (&$coreCalls): SearchResultsInterface {
            ++$coreCalls;
            return $this->createStub(SearchResultsInterface::class);
        };

        try {
            (new ServeSearchFromDocuments($hydration))->aroundGetList(
                $this->createStub(ProductSearch::class), $proceed,
                $this->createStub(SearchCriteriaInterface::class), $search
            );
            self::fail('The document read must throw.');
        } catch (DocumentReadException $error) {
            self::assertSame($storageFailure ? $cause : null, $error->getPrevious());
            self::assertStringContainsString($storageFailure ? 'Storage unavailable.' : 'no document for product 42', $error->getMessage());
        }
        self::assertSame(0, $coreCalls);
        self::assertCount($keyed ? 1 : 0, $strict->report()['fallbacks']);
    }

    public static function failures(): iterable
    {
        yield 'ordinary missing document' => [false, false];
        yield 'keyed missing document' => [true, false];
        yield 'ordinary storage failure' => [false, true];
        yield 'keyed storage failure' => [true, true];
    }

    public function testCoreModeUsesTheCoreReader(): void
    {
        $hydration = $this->createMock(DocumentHydration::class);
        $hydration->method('enabled')->willReturn(false);
        $hydration->expects(self::never())->method('rebuildFromIds');
        $expected = $this->createStub(SearchResultsInterface::class);
        $result = (new ServeSearchFromDocuments($hydration))->aroundGetList(
            $this->createStub(ProductSearch::class), static fn() => $expected,
            $this->createStub(SearchCriteriaInterface::class),
            $this->createStub(SearchResultInterface::class)
        );
        self::assertSame($expected, $result);
    }
}
