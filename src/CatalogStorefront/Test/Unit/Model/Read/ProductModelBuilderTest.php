<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use PHPUnit\Framework\TestCase;

/**
 * What a built model owes a page that cannot tell it from a loaded one.
 */
class ProductModelBuilderTest extends TestCase
{
    /** @var array<string, mixed> the data the model was given */
    private array $data = [];

    private int $origData = 0;

    private function builder(): ProductModelBuilder
    {
        $product = $this->createMock(Product::class);
        $product->method('setData')->willReturnCallback(
            function ($key, $value = null) use ($product) {
                if (is_array($key)) {
                    $this->data += $key;
                } else {
                    $this->data[$key] = $value;
                }

                return $product;
            }
        );
        $product->method('setOrigData')->willReturnCallback(
            function () use ($product) {
                $this->origData++;

                return $product;
            }
        );

        $factory = $this->createMock(ProductFactory::class);
        $factory->method('create')->willReturn($product);

        return new ProductModelBuilder($factory, new ProductPrice());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function build(array $overrides = []): ?Product
    {
        return $this->builder()->build($overrides + [
            'sku' => 'WT09',
            'productId' => 1812,
            'name' => 'Breathe-Easy Tank',
            'type' => 'configurable',
            'status' => 'Enabled',
        ], 1);
    }

    public function testADocumentWithoutTheProductsSliceBuildsNothing(): void
    {
        // Only the prices or the stock feed has arrived for this product.
        $this->assertNull($this->builder()->build(['productId' => 1812], 1));
    }

    public function testTheIdIsTheStringTheEntityTableGives(): void
    {
        // getJsonConfig() puts the id into the rendered JSON as it is, so an int renders as 1812
        // where a loaded product renders "1812".
        $this->build();

        $this->assertSame('1812', $this->data['entity_id']);
    }

    public function testTheOptionListIsAnArray(): void
    {
        // A detail page counts it, and null is not countable.
        $this->build();

        $this->assertSame([], $this->data['options']);
    }

    public function testTheDocumentBecomesTheOriginalValues(): void
    {
        // Without them every comparison against them reads as a change: getIdentities() takes a
        // changed status to mean the product moved category and adds a cache tag per category.
        $this->build();

        $this->assertSame(1, $this->origData);
    }

    public function testTheDocumentTravelsOnTheModel(): void
    {
        $this->build();

        $this->assertSame('WT09', $this->data[ProductDocumentsInterface::DOCUMENT_KEY]['sku']);
    }

    public function testAFixedBundleIsBuiltAsABundle(): void
    {
        // The feed folds the price type into the product type; the model carries them apart.
        $this->build(['type' => 'bundle_fixed']);

        $this->assertSame('bundle', $this->data['type_id']);
        $this->assertSame('1', $this->data['price_type']);
    }

    public function testCategoryIdsComeFromTheCategoryData(): void
    {
        $this->build(['categoryData' => [['categoryId' => 24], ['categoryId' => 25]]]);

        $this->assertSame(['24', '25'], $this->data['category_ids']);
    }
}
