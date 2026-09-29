<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Writer\Attributes;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class AttributesTest extends TestCase
{
    public function testPartialUpdatesAndDeletesRefreshTheStoreFilterOrder(): void
    {
        $color = new DataObject(['attribute_code' => 'color']);
        $size = new DataObject(['attribute_code' => 'size']);
        $category = $this->createMock(Collection::class);
        $search = $this->createMock(Collection::class);
        foreach ([$category, $search] as $collection) {
            foreach (['setItemObjectClass', 'addStoreLabel', 'setOrder', 'addIsFilterableInSearchFilter', 'addVisibleFilter', 'addIsFilterableFilter'] as $method) {
                $collection->method($method)->willReturnSelf();
            }
        }
        $category->method('getItems')->willReturnOnConsecutiveCalls([93 => $color, 141 => $size], [141 => $size]);
        $search->method('getItems')->willReturnOnConsecutiveCalls([141 => $size, 93 => $color], [141 => $size]);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls($category, $search, $category, $search);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->with('default')->willReturn($store);
        $writes = [];
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects(self::exactly(2))->method('upsert')->willReturnCallback(static function ($entity, $store, $documents) use (&$writes): void {
            $writes[] = $documents;
        });
        $storage->expects(self::once())->method('delete')->with('attribute', 'default', ['color']);
        $writer = new Attributes($storage, $factory, $stores);
        $row = ['attributeCode' => 'color', 'storeViewCode' => 'default', 'label' => 'Colour'];

        $writer->write([$row]);
        $writer->write([['attributeCode' => 'color', 'storeViewCode' => 'default', 'deleted' => true]]);

        self::assertSame([
            'color' => $row + ['categoryFilterOrder' => 0, 'searchFilterOrder' => 1],
            'size' => ['categoryFilterOrder' => 1, 'searchFilterOrder' => 0],
        ], $writes[0]);
        self::assertSame(['size' => ['categoryFilterOrder' => 0, 'searchFilterOrder' => 0]], $writes[1]);
    }
}
