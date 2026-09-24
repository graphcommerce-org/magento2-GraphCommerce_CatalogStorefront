<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Plugin\HasChildrenFromDocuments;
use Magento\Catalog\Model\Category;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class HasChildrenFromDocumentsTest extends TestCase
{
    private function plugin(array $documents): HasChildrenFromDocuments
    {
        $reader = $this->createMock(CategoryDocuments::class);
        $reader->method('documents')->willReturnCallback(
            static fn (string $store, array $ids) => array_intersect_key($documents, array_flip($ids))
        );
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new HasChildrenFromDocuments($reader, $storeManager);
    }

    private function category(?array $document): Category
    {
        $category = $this->createMock(Category::class);
        $category->method('getData')->with(CategoryDocuments::DOCUMENT_KEY)->willReturn($document);
        $category->method('getStoreId')->willReturn(1);

        return $category;
    }

    public function testAnActiveGrandchildUnderAnInactiveChildCounts(): void
    {
        $plugin = $this->plugin([
            11 => ['isActive' => 0, 'children' => ['12']],
            12 => ['isActive' => 1, 'children' => []],
        ]);

        $this->assertTrue($plugin->aroundHasChildren($this->category(['children' => ['11']]), fn () => false));
    }

    public function testOnlyInactiveDescendantsMeanNoChildren(): void
    {
        $plugin = $this->plugin([11 => ['isActive' => 0, 'children' => []]]);

        $this->assertFalse($plugin->aroundHasChildren($this->category(['children' => ['11']]), fn () => true));
    }

    public function testACategoryWithoutADocumentAsksCore(): void
    {
        $this->assertTrue($this->plugin([])->aroundHasChildren($this->category(null), fn () => true));
    }
}
