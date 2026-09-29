<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\LoadedCollection;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\LoadedCollectionFactory;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Plugin\ResourceFromDocuments;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ResourceFromDocumentsTest extends TestCase
{
    /** @var Category[] the models the plugin handed to the collection */
    private array $collected = [];

    private bool $coreAsked = false;

    /**
     * @param array<int, array<string, mixed>> $models the data of the built model per id
     */
    private function plugin(array $models, bool $listing = true): ResourceFromDocuments
    {
        $this->collected = [];
        $this->coreAsked = false;

        $reader = $this->createMock(CategoryDocuments::class);
        $reader->method('models')->willReturnCallback(function (string $store, int $storeId, array $ids) use ($models) {
            $built = [];
            foreach ($ids as $id) {
                if (isset($models[$id])) {
                    $built[$id] = $this->model($models[$id] + ['entity_id' => $id]);
                }
            }

            return $built;
        });

        $mode = $this->createMock(Mode::class);
        $mode->method('listing')->willReturn($listing);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $collection = $this->createMock(LoadedCollection::class);
        $collection->method('withItems')->willReturnCallback(function (array $items) use ($collection) {
            $this->collected = $items;

            return $collection;
        });
        $collectionFactory = $this->createMock(LoadedCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $categoryFactory = $this->createMock(CategoryFactory::class);
        $categoryFactory->method('create')->willReturnCallback(fn () => $this->model([]));

        return new ResourceFromDocuments($reader, $mode, $storeManager, $collectionFactory, $categoryFactory);
    }

    private function model(array $data): Category
    {
        $category = $this->createMock(Category::class);
        $category->method('getData')->willReturnCallback(static fn (?string $key = null) => $key === null ? $data : ($data[$key] ?? null));
        $category->method('getId')->willReturn($data['entity_id'] ?? null);
        $category->method('getStoreId')->willReturn(1);
        $category->method('getPathIds')->willReturn(explode('/', (string)($data['path'] ?? '')));
        $category->method('getPathInStore')->willReturn((string)($data['path_in_store'] ?? ''));

        return $category;
    }

    private function proceed(): \Closure
    {
        return function () {
            $this->coreAsked = true;

            return 'core';
        };
    }

    public function testTheActiveChildrenComeSortedByPosition(): void
    {
        $plugin = $this->plugin([
            11 => ['is_active' => 1, 'position' => 2],
            12 => ['is_active' => 0, 'position' => 1],
            13 => ['is_active' => 1, 'position' => 1],
        ]);
        $parent = $this->model([CategoryDocuments::DOCUMENT_KEY => ['children' => ['11', '12', '13']]]);

        $plugin->aroundGetChildrenCategories($this->createMock(CategoryResource::class), $this->proceed(), $parent);

        $this->assertSame([13, 11], array_keys($this->collected));
        $this->assertFalse($this->coreAsked);
    }

    public function testTheActiveParentsComeInPathOrder(): void
    {
        $plugin = $this->plugin([
            34 => ['is_active' => 1],
            10 => ['is_active' => 1],
            2 => ['is_active' => 0],
        ]);
        $category = $this->model([CategoryDocuments::DOCUMENT_KEY => [], 'path_in_store' => '10,34']);

        $parents = $plugin->aroundGetParentCategories($this->createMock(CategoryResource::class), $this->proceed(), $category);

        $this->assertSame([34, 10], array_keys($parents));
    }

    public function testTheDesignParentIsTheDeepestWithItsOwnSettings(): void
    {
        $plugin = $this->plugin([
            1 => ['level' => 0, 'custom_use_parent_settings' => null],
            2 => ['level' => 1, 'custom_use_parent_settings' => '0'],
            34 => ['level' => 2, 'custom_use_parent_settings' => '0'],
            10 => ['level' => 3, 'custom_use_parent_settings' => '1'],
        ]);
        $category = $this->model([CategoryDocuments::DOCUMENT_KEY => [], 'path' => '1/2/34/10']);

        $design = $plugin->aroundGetParentDesignCategory($this->createMock(CategoryResource::class), $this->proceed(), $category);

        $this->assertSame(34, $design->getId());
    }

    public function testACategoryWithoutADocumentAsksCore(): void
    {
        $plugin = $this->plugin([]);
        $category = $this->model([]);

        $this->assertSame('core', $plugin->aroundGetChildrenCategories($this->createMock(CategoryResource::class), $this->proceed(), $category));
        $this->assertSame('core', $plugin->aroundGetParentCategories($this->createMock(CategoryResource::class), $this->proceed(), $category));
        $this->assertSame('core', $plugin->aroundGetParentDesignCategory($this->createMock(CategoryResource::class), $this->proceed(), $category));
    }

    public function testALoadWhileTheListingSettingIsOffAsksCore(): void
    {
        $plugin = $this->plugin([10 => []], false);

        $this->assertSame('core', $plugin->aroundLoad($this->createMock(CategoryResource::class), $this->proceed(), $this->model([]), 10));
    }

    public function testAMissingCategoryDocumentFails(): void
    {
        $plugin = $this->plugin([]);
        $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        try {
            $plugin->aroundLoad($this->createMock(CategoryResource::class), $this->proceed(), $this->model([]), 10);
        } finally {
            self::assertFalse($this->coreAsked);
        }
    }
}
