<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read\ConfigurableOptions;
use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Plugin\Listing\ConfigurableAttributesFromDocument;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute as ProductAttribute;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable\AttributeFactory;
use Magento\Eav\Model\Config as EavConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigurableAttributesFromDocumentTest extends TestCase
{
    private const MEMO = '_cache_instance_configurable_attributes';

    /** @var array<int, array> the data each built Attribute was given */
    private array $built = [];

    /**
     * @param bool $attributeResolves whether EavConfig knows the attribute code
     */
    private function plugin(bool $attributeResolves = true): ConfigurableAttributesFromDocument
    {
        $factory = $this->createMock(AttributeFactory::class);
        $factory->method('create')->willReturnCallback(function (): Attribute {
            $attribute = $this->createMock(Attribute::class);
            $attribute->method('setData')->willReturnCallback(
                function (array $data) use ($attribute): Attribute {
                    $this->built[] = $data;

                    return $attribute;
                }
            );

            return $attribute;
        });

        // The class EavConfig::getAttribute() actually returns for a product attribute. The
        // abstract does not declare getStoreLabel(), which the plugin uses as its label fallback.
        $productAttribute = $this->createMock(ProductAttribute::class);
        $productAttribute->method('getId')->willReturn($attributeResolves ? 93 : null);
        $productAttribute->method('getStoreLabel')->willReturn('Colour');

        $eavConfig = $this->createMock(EavConfig::class);
        $eavConfig->method('getAttribute')->willReturn($attributeResolves ? $productAttribute : null);

        return new ConfigurableAttributesFromDocument(
            $factory,
            $eavConfig,
            $this->createMock(LoggerInterface::class),
            new ConfigurableOptions()
        );
    }

    private function product(?array $options, bool $memoised = false): Product
    {
        $document = $options === null ? null : ['productId' => 7, 'configurableOptions' => $options];

        $product = $this->createMock(Product::class);
        $product->method('hasData')->willReturnCallback(
            static fn(string $key) => $key === self::MEMO && $memoised
        );
        $product->method('getData')->willReturnCallback(
            static fn(string $key) => match ($key) {
                ProductDocumentsInterface::DOCUMENT_KEY => $document,
                self::MEMO => $memoised ? ['memoised'] : null,
                default => null,
            }
        );

        return $product;
    }

    private function proceed(): \Closure
    {
        return static fn() => 'from core';
    }

    private function option(array $overrides = []): array
    {
        return $overrides + [
            'id' => 5,
            'code' => 'color',
            'attribute' => 93,
            'position' => 0,
            'label' => 'Colour',
            'useDefault' => false,
            'values' => [['index' => 1, 'label' => 'Red']],
        ];
    }

    public function testPositionIsPassedAsAString(): void
    {
        // The database returns position as a string and getJsonConfig() puts it straight into
        // JSON, so an int changes the rendered page — 2,967 bytes, and it also suppressed a
        // PageBuilder script, so the visible symptom was nowhere near the cause.
        $this->plugin()->aroundGetConfigurableAttributes(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->product([$this->option(['position' => 3])])
        );

        $this->assertSame('3', $this->built[0]['position']);
    }

    public function testAMissingPositionStillBecomesAString(): void
    {
        $option = $this->option();
        unset($option['position']);

        $this->plugin()->aroundGetConfigurableAttributes(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->product([$option])
        );

        $this->assertSame('0', $this->built[0]['position']);
    }

    public function testTakesTheAttributeIdAndLabelFromTheDocument(): void
    {
        $this->plugin()->aroundGetConfigurableAttributes(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->product([$this->option(['attribute' => 163, 'label' => 'Size'])])
        );

        $this->assertSame('163', $this->built[0]['attribute_id']);
        $this->assertSame('Size', $this->built[0]['label']);
    }

    public function testOptionValuesArePassedThroughAsAList(): void
    {
        $values = [['index' => 1, 'label' => 'Red'], ['index' => 2, 'label' => 'Blue']];

        $this->plugin()->aroundGetConfigurableAttributes(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->product([$this->option(['values' => $values])])
        );

        $this->assertSame(['1', '2'], array_column($this->built[0]['options'], 'value_index'));
        $this->assertSame(['Red', 'Blue'], array_column($this->built[0]['options'], 'label'));
    }

    public function testUsesCoresOwnMemoWhenItIsAlreadySet(): void
    {
        $result = $this->plugin()->aroundGetConfigurableAttributes(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->product([$this->option()], true)
        );

        $this->assertSame(['memoised'], $result);
    }

    public function testACoreProductUsesCore(): void
    {
        $result = $this->plugin()->aroundGetConfigurableAttributes(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->product(null)
        );

        $this->assertSame('from core', $result);
    }

    public function testAnUnresolvedAttributeFails(): void
    {
        $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        $this->plugin(false)->aroundGetConfigurableAttributes(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->product([$this->option()])
        );
    }

    public function testAMissingAttributeCodeFails(): void
    {
        $option = $this->option();
        unset($option['code']);

        $this->expectException(\GraphCommerce\CatalogStorefront\Model\DocumentReadException::class);
        $this->plugin()->aroundGetConfigurableAttributes(
            $this->createMock(Configurable::class),
            $this->proceed(),
            $this->product([$option])
        );
    }
}
