<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver\AttributeValueTypeFromDocument;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver\CustomAttributesFromDocument;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use Magento\Catalog\Model\FilterProductCustomAttribute;
use Magento\Catalog\Model\Product;
use Magento\CatalogGraphQl\Model\Resolver\Product\ProductCustomAttributes;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * A value row that holds NULL lands on the document without a value: core
 * answers it as the blank option of a select, as no option of a multiselect
 * and as an empty string of any other input.
 */
class CustomAttributesFromDocumentTest extends TestCase
{
    private const ATTRIBUTES = [
        'color' => ['attributeId' => 93, 'visible' => true, 'frontendInput' => 'select', 'options' => [['id' => '5', 'label' => 'Red']]],
        'tags' => ['attributeId' => 94, 'visible' => true, 'frontendInput' => 'multiselect', 'options' => [['id' => '8', 'label' => 'Sale']]],
        'note' => ['attributeId' => 95, 'visible' => true, 'frontendInput' => 'text'],
    ];

    /**
     * @param list<array{attributeCode: string, value?: ?string}> $customAttributes
     * @return array<string, array> the items by attribute code
     */
    private function items(array $customAttributes): array
    {
        $documents = $this->createMock(AttributeDocuments::class);
        $documents->method('byCodes')->willReturnCallback(
            static fn(string $store, array $codes): array => array_intersect_key(self::ATTRIBUTES, array_flip($codes))
        );
        $filter = $this->createMock(FilterProductCustomAttribute::class);
        $filter->method('execute')->willReturnArgument(0);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $extension = $this->createMock(ContextExtensionInterface::class);
        $extension->method('getStore')->willReturn($store);
        $context = $this->createMock(ContextInterface::class);
        $context->method('getExtensionAttributes')->willReturn($extension);
        $product = $this->createMock(Product::class);
        $product->method('getData')->with(HydrationInterface::DOCUMENT_KEY)->willReturn(['customAttributes' => $customAttributes]);

        $plugin = new CustomAttributesFromDocument($documents, $filter, $this->createMock(Strict::class));
        $result = $plugin->aroundResolve(
            $this->createMock(ProductCustomAttributes::class),
            static fn() => self::fail('the document answers'),
            $this->createMock(Field::class),
            $context,
            $this->createMock(ResolveInfo::class),
            ['model' => $product],
            []
        );

        return array_column($result['items'], null, 'code');
    }

    public function testANullValueSelectsTheBlankOptionOfASelectAndNothingOfAMultiselect(): void
    {
        $items = $this->items([['attributeCode' => 'color'], ['attributeCode' => 'tags', 'value' => null]]);

        self::assertSame([['value' => '', 'label' => ' ']], $items['color']['selected_options']);
        self::assertSame([], $items['tags']['selected_options']);
    }

    public function testAnEmptyValueSelectsTheBlankOptionOfBoth(): void
    {
        $items = $this->items([['attributeCode' => 'color', 'value' => ''], ['attributeCode' => 'tags', 'value' => '']]);

        self::assertSame([['value' => '', 'label' => ' ']], $items['color']['selected_options']);
        self::assertSame([['value' => '', 'label' => ' ']], $items['tags']['selected_options']);
    }

    public function testAValueSelectsItsOptions(): void
    {
        $items = $this->items([['attributeCode' => 'color', 'value' => '5'], ['attributeCode' => 'tags', 'value' => '8,9']]);

        self::assertSame([['value' => '5', 'label' => 'Red']], $items['color']['selected_options']);
        self::assertSame([['value' => '8', 'label' => 'Sale']], $items['tags']['selected_options']);
        self::assertSame('AttributeSelectedOptions', $items['tags'][AttributeValueTypeFromDocument::KEY]);
    }

    public function testANullTextValueIsAnEmptyString(): void
    {
        $items = $this->items([['attributeCode' => 'note'], ['attributeCode' => 'color', 'value' => '5']]);

        self::assertSame('', $items['note']['value']);
        self::assertSame('AttributeValue', $items['note'][AttributeValueTypeFromDocument::KEY]);
    }
}
