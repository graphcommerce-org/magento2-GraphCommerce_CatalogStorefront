<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Read\CategoryData;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class CategoryDocumentsTest extends TestCase
{
    /** @var int[][] the id lists the storage was asked for */
    private array $reads = [];

    private function documents(array $stored): CategoryDocuments
    {
        $this->reads = [];
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->method('get')->willReturnCallback(function (string $entity, string $store, array $ids) use ($stored) {
            $this->reads[] = $ids;

            return array_intersect_key($stored, array_flip($ids));
        });

        $factory = $this->createMock(CategoryFactory::class);
        $factory->method('create')->willReturnCallback(
            function () {
                $category = $this->getMockBuilder(Category::class)->disableOriginalConstructor()->onlyMethods(['getResource'])->getMock();
                $category->method('getResource')->willReturn($this->createMock(\Magento\Catalog\Model\ResourceModel\Category::class));
                $category->setIdFieldName('entity_id');

                return $category;
            }
        );

        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('.html');

        return new CategoryDocuments($storage, new CategoryData(), $factory, $config);
    }

    public function testEachDocumentIsReadOnce(): void
    {
        $documents = $this->documents([10 => ['categoryId' => '10'], 11 => ['categoryId' => '11']]);

        $documents->documents('default', [10, 11]);
        $found = $documents->documents('default', [11, 12]);

        $this->assertSame([11], array_keys($found));
        $this->assertSame([[10, 11], [12]], $this->reads);
    }

    public function testAnIdWithoutADocumentIsNotReadAgain(): void
    {
        $documents = $this->documents([]);

        $documents->documents('default', [12]);
        $documents->documents('default', [12]);

        $this->assertSame([[12]], $this->reads);
    }

    public function testABuiltCategoryCarriesItsDocumentAndItsRequestPath(): void
    {
        $documents = $this->documents([10 => [
            'categoryId' => '10',
            'urlPath' => 'clubkleding/hurley',
            'image' => 'https://shop.example/media/catalog/category/hurley.png',
            'customAttributes' => [['attributeCode' => 'image', 'value' => '/media/catalog/category/hurley.png']],
        ]]);

        $category = $documents->models('default', 1, [10])[10];

        $this->assertSame('clubkleding/hurley.html', $category->getData('request_path'));
        $this->assertSame('/media/catalog/category/hurley.png', $category->getData('image'));
        $this->assertSame('10', $category->getData(CategoryDocuments::DOCUMENT_KEY)['categoryId']);
        $this->assertSame(1, $category->getStoreId());
    }

    public function testABuiltCategoryHasNoChangesAndCarriesOnlyItsOwnCacheTag(): void
    {
        $documents = $this->documents([25 => [
            'categoryId' => '25',
            'path' => '1/2/24/25',
            'isAnchor' => true,
            'isActive' => true,
            'includeInMenu' => true,
        ]]);

        $category = $documents->models('default', 1, [25])[25];

        $this->assertFalse($category->hasDataChanges());
        $this->assertSame(['cat_c_25'], $category->getIdentities());
    }

    public function testACategoryReadAloneBringsItsParentsAndChildrenInOneMoreRead(): void
    {
        $documents = $this->documents([
            1 => ['categoryId' => '1'],
            3 => ['categoryId' => '3'],
            10 => ['categoryId' => '10', 'path' => '1/3/10', 'children' => ['11', '12']],
            11 => ['categoryId' => '11'],
            12 => ['categoryId' => '12'],
        ]);

        $documents->documents('default', [10]);
        $documents->documents('default', [1, 3]);
        $documents->documents('default', [11, 12]);

        $this->assertSame([[10], [1, 3, 11, 12]], $this->reads);
    }

    public function testSeveralCategoriesReadTogetherBringNothingAlong(): void
    {
        $documents = $this->documents([10 => ['categoryId' => '10', 'path' => '1/10', 'children' => ['11']]]);

        $documents->documents('default', [10, 20]);

        $this->assertSame([[10, 20]], $this->reads);
    }
}
