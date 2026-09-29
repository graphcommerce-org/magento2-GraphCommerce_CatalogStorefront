<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\LoadedAttributeCollection;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\LoadedAttributeCollectionFactory;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\FilterableAttributesFromDocuments;
use Magento\Catalog\Model\Layer\Category\FilterableAttributeList as CategoryList;
use Magento\Catalog\Model\Layer\Search\FilterableAttributeList as SearchList;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class FilterableAttributesFromDocumentsTest extends TestCase
{
    /** @var array<string, string> code to store label of the listed attributes, in order */
    private array $listed = [];

    private int $reads = 0;

    private bool $coreAsked = false;

    private function plugin(array $documents, array $known): FilterableAttributesFromDocuments
    {
        $this->listed = [];
        $this->reads = 0;
        $this->coreAsked = false;

        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->method('find')->willReturnCallback(function (string $entity, string $store, array $filter) use ($documents) {
            $this->reads++;
            $found = array_filter($documents, static fn (array $document) => isset($filter['filterableMode'])
                ? in_array((int)$document['filterableMode'], $filter['filterableMode'], true)
                : $document['filterableInSearch'] && $document['visible']);

            return ['documents' => $found, 'total' => count($found)];
        });
        $storage->method('count')->willReturn(count($documents));

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturnCallback(function (string $entity, string $code) use ($known) {
            $attribute = $this->getMockBuilder(Attribute::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
            $attribute->method('getId')->willReturn($known[$code] ?? null);
            $attribute->setData('attribute_code', $code);

            return $attribute;
        });

        $mode = $this->createMock(Mode::class);
        $mode->method('listing')->willReturn(true);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $collection = $this->createMock(LoadedAttributeCollection::class);
        $collection->method('withItems')->willReturnCallback(function (array $attributes) use ($collection) {
            foreach ($attributes as $attribute) {
                $this->listed[$attribute->getData('attribute_code')] = $attribute->getData('store_label');
            }

            return $collection;
        });
        $factory = $this->createMock(LoadedAttributeCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new FilterableAttributesFromDocuments($storage, $eavConfig, $mode, $storeManager, $factory);
    }

    private function documents(): array
    {
        return [
            'color' => ['attributeCode' => 'color', 'attributeId' => 93, 'filterableMode' => 1, 'filterableInSearch' => true, 'visible' => true, 'position' => 0, 'categoryFilterOrder' => 1, 'searchFilterOrder' => 0, 'label' => 'Kleur'],
            'size' => ['attributeCode' => 'size', 'attributeId' => 141, 'filterableMode' => 2, 'filterableInSearch' => false, 'visible' => true, 'position' => 0, 'categoryFilterOrder' => 0, 'label' => 'Maat'],
            'cost' => ['attributeCode' => 'cost', 'attributeId' => 81, 'filterableMode' => 0, 'filterableInSearch' => true, 'visible' => false, 'position' => 0, 'label' => 'Cost'],
        ];
    }

    private function proceed(): \Closure
    {
        return function () {
            $this->coreAsked = true;

            return 'core';
        };
    }

    public function testEqualPositionsUseTheExportedCoreOrder(): void
    {
        $plugin = $this->plugin($this->documents(), ['color' => 93, 'size' => 141]);

        $plugin->aroundGetList($this->createMock(CategoryList::class), $this->proceed());

        $this->assertSame(['size' => 'Maat', 'color' => 'Kleur'], $this->listed);
        $this->assertFalse($this->coreAsked);
    }

    public function testTheSearchLayerListsTheVisibleAttributesFilterableInSearch(): void
    {
        $plugin = $this->plugin($this->documents(), ['color' => 93, 'size' => 141]);

        $plugin->aroundGetList($this->createMock(SearchList::class), $this->proceed());

        $this->assertSame(['color' => 'Kleur'], $this->listed);
    }

    public function testEachLayerReadsItsDocumentsOnce(): void
    {
        $plugin = $this->plugin($this->documents(), ['color' => 93, 'size' => 141]);

        $plugin->aroundGetList($this->createMock(CategoryList::class), $this->proceed());
        $plugin->aroundGetList($this->createMock(CategoryList::class), $this->proceed());
        $plugin->aroundGetList($this->createMock(SearchList::class), $this->proceed());

        $this->assertSame(2, $this->reads);
    }

    public function testAnUnresolvedAttributeFails(): void
    {
        $plugin = $this->plugin($this->documents(), ['color' => 93]);

        $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        try {
            $plugin->aroundGetList($this->createMock(CategoryList::class), $this->proceed());
        } finally {
            self::assertFalse($this->coreAsked);
        }
    }

    public function testMissingFilterOrderFails(): void
    {
        $documents = $this->documents();
        unset($documents['color']['categoryFilterOrder']);
        $plugin = $this->plugin($documents, ['color' => 93, 'size' => 141]);

        $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        $plugin->aroundGetList($this->createMock(CategoryList::class), $this->proceed());
    }

    public function testALayerWithoutAFilterableAttributeIsAnEmptyList(): void
    {
        $plugin = $this->plugin(['cost' => $this->documents()['cost']], ['cost' => 81]);

        $plugin->aroundGetList($this->createMock(SearchList::class), $this->proceed());

        $this->assertSame([], $this->listed);
        $this->assertFalse($this->coreAsked);
    }

    public function testMissingAttributeDocumentsFail(): void
    {
        $plugin = $this->plugin([], []);

        $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        try {
            $plugin->aroundGetList($this->createMock(CategoryList::class), $this->proceed());
        } finally {
            self::assertFalse($this->coreAsked);
        }
    }
}
