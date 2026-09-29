<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\CollectionFlag;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\ListingHydration;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\Product;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection as SearchCollection;
use Magento\Customer\Model\Session;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ListingHydrationTest extends TestCase
{
    #[DataProvider('collections')]
    public function testPagingAndCategoryPositionsFollowTheCollection(string $type, bool $category): void
    {
        $rows = [['entity_id' => 42, 'cat_index_position' => 30], ['entity_id' => 43, 'cat_index_position' => 20]];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn($rows);
        $select = $this->createMock(Select::class);
        $select->expects($type === SearchCollection::class ? self::never() : self::once())
            ->method('limitPage')->with(2, 2);
        $collection = $this->createMock($type);
        $collection->method('getFlag')->willReturnMap([[CollectionFlag::FLAG, true], ['has_category_filter', $category]]);
        $collection->method('getStoreId')->willReturn(1);
        $collection->method('getPageSize')->willReturn(2);
        $collection->method('getCurPage')->willReturn(2);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getConnection')->willReturn($connection);
        $collection->expects($category ? self::once() : self::never())->method('setFlag')->with('has_category_filter', false);
        $documents = [42 => ['type' => 'simple'], 43 => ['type' => 'simple']];
        $models = [];
        foreach ($rows as $position => $row) {
            $model = $this->createMock(Product::class);
            if ($category) {
                $row['cat_index_position'] = $position;
            }
            $model->expects(self::once())->method('addData')->with($row);
            $models[$row['entity_id']] = $model;
        }
        $added = [];
        $collection->expects(self::exactly(2))->method('addItem')
            ->willReturnCallback(static function ($model) use (&$added, $collection) {
                $added[] = $model;
                return $collection;
            });
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $storage->expects(self::once())->method('listing')->with('default', [42, 43], '0')->willReturn([$documents, []]);
        $products = $this->createStub(ProductDocumentsInterface::class);
        $products->method('build')->willReturn($models);
        $mode = $this->createStub(Mode::class);
        $mode->method('listing')->willReturn(true);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $page = $this->createMock(ListingDocuments::class);
        $page->expects(self::once())->method('add')->with('default', $documents, []);
        $plugin = new ListingHydration(
            $storage, $products, $mode, $stores, $page, new ProductPrice(),
            $this->createStub(Session::class), $this->createStub(LoggerInterface::class)
        );

        self::assertSame($collection, $plugin->around_loadEntities($collection, static function () {
            self::fail('The core entity reader was called.');
        }));
        self::assertSame(array_values($models), $added);
    }

    public static function collections(): iterable
    {
        yield 'native category search' => [SearchCollection::class, true];
        yield 'native text search' => [SearchCollection::class, false];
        yield 'product collection' => [Collection::class, false];
    }

    #[DataProvider('failures')]
    public function testDocumentFailuresStopTheCoreReader(bool $storageFailure): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('fetchAll')->willReturn([['entity_id' => 42]]);
        $collection = $this->createMock(Collection::class);
        $collection->method('getFlag')->with(CollectionFlag::FLAG)->willReturn(true);
        $collection->method('getStoreId')->willReturn(1);
        $collection->method('getSelect')->willReturn($this->createStub(Select::class));
        $collection->method('getConnection')->willReturn($connection);
        $collection->expects(self::never())->method('addItem');
        $storage = $this->createMock(ProductDocumentStorageInterface::class);
        $read = $storage->expects(self::once())->method('listing');
        if ($storageFailure) {
            $read->willThrowException(new \RuntimeException('Storage unavailable.'));
        } else {
            $read->willReturn([[], []]);
        }
        $products = $this->createStub(ProductDocumentsInterface::class);
        $products->method('build')->willReturn([]);
        $mode = $this->createStub(Mode::class);
        $mode->method('listing')->willReturn(true);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $page = $this->createMock(ListingDocuments::class);
        $page->expects(self::never())->method('add');
        $plugin = new ListingHydration(
            $storage, $products, $mode, $stores, $page, new ProductPrice(),
            $this->createStub(Session::class), $this->createStub(LoggerInterface::class)
        );
        $coreCalls = 0;
        $proceed = static function () use (&$coreCalls, $collection): Collection {
            ++$coreCalls;
            return $collection;
        };

        $this->expectException(DocumentReadException::class);
        try {
            $plugin->around_loadEntities($collection, $proceed);
        } finally {
            self::assertSame(0, $coreCalls);
        }
    }

    public static function failures(): iterable
    {
        yield 'missing document' => [false];
        yield 'storage failure' => [true];
    }
}
