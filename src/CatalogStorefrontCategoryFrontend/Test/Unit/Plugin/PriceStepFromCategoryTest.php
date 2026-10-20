<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Plugin\PriceStepFromCategory;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer\Filter\Price\Range;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class PriceStepFromCategoryTest extends TestCase
{
    private function step(?Category $category): mixed
    {
        $registry = new Registry();
        if ($category !== null) {
            $registry->register('current_category_filter', $category);
        }

        return (new PriceStepFromCategory($registry))->aroundGetPriceRange(
            $this->createMock(Range::class),
            static fn() => 'next'
        );
    }

    private function category(?array $document): Category
    {
        $category = $this->getMockBuilder(Category::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        if ($document !== null) {
            $category->setData(CategoryDocuments::DOCUMENT_KEY, $document);
        }

        return $category;
    }

    public function testTheStepComesFromTheDocumentOfTheFilteredCategory(): void
    {
        $this->assertSame(25.0, $this->step($this->category(['filterPriceRange' => 25.0])));
        $this->assertNull($this->step($this->category(['filterPriceRange' => null])));
    }

    public function testACategoryWithoutTheFieldOrWithoutADocumentGoesOn(): void
    {
        $this->assertSame('next', $this->step($this->category(['id' => 3])));
        $this->assertSame('next', $this->step($this->category(null)));
        $this->assertSame('next', $this->step(null));
    }
}
