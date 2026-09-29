<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use Psr\Log\LoggerInterface;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver\AttributesListFromDocuments;
use Magento\EavGraphQl\Model\Resolver\AttributesList;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\EnumLookup;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextExtensionInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class AttributesListFromDocumentsTest extends TestCase
{
    private const DOCUMENTS = [
        'color' => [
            'attributeCode' => 'color', 'attributeId' => 93, 'label' => 'Colour', 'position' => 3, 'frontendInput' => 'select',
            'backendType' => 'int', 'isVisible' => true, 'required' => false, 'unique' => false, 'defaultValue' => '5',
            'filterableMode' => 1, 'searchable' => true, 'visibleInListing' => true, 'visibleInSearch' => false,
            'applyTo' => 'simple,configurable', 'additionalData' => '{"swatch_input_type":"visual","update_product_preview_image":1}',
            'options' => [['id' => '5', 'label' => 'Red'], ['id' => '6', 'label' => 'Blue'], ['id' => '', 'label' => ' ']],
        ],
        'size' => [
            'attributeCode' => 'size', 'attributeId' => 94, 'label' => 'Size', 'frontendInput' => 'select', 'backendType' => 'int',
            'isVisible' => true, 'filterableMode' => 2, 'searchable' => true, 'options' => [['id' => '8', 'label' => 'S']],
        ],
        'sku' => ['attributeCode' => 'sku', 'attributeId' => 1, 'backendType' => 'static', 'isVisible' => true, 'filterableMode' => 1],
        'hidden' => ['attributeCode' => 'hidden', 'attributeId' => 2, 'backendType' => 'varchar', 'isVisible' => false, 'filterableMode' => 1],
        'name' => [
            'attributeCode' => 'name', 'attributeId' => 73, 'label' => 'Name', 'frontendInput' => 'text', 'backendType' => 'varchar',
            'isVisible' => true, 'required' => true, 'filterableMode' => 0, 'searchable' => true, 'visibleInListing' => true,
            'frontendClass' => 'validate-length maximum-length-255', 'defaultValue' => null, 'options' => null,
        ],
    ];

    private AttributeDocuments $documents;
    private Strict $strict;
    private Mode $mode;
    private ContextInterface $context;

    protected function setUp(): void
    {
        $this->documents = $this->createMock(AttributeDocuments::class);
        $this->strict = new Strict($this->createStub(StorefrontKey::class), $this->createStub(LoggerInterface::class));
        $this->mode = $this->createMock(Mode::class);
        $this->mode->method('documents')->willReturn(true);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $extension = $this->createMock(ContextExtensionInterface::class);
        $extension->method('getStore')->willReturn($store);
        $this->context = $this->createMock(ContextInterface::class);
        $this->context->method('getExtensionAttributes')->willReturn($extension);
    }

    private function plugin(): AttributesListFromDocuments
    {
        $enums = $this->createMock(EnumLookup::class);
        $enums->method('getEnumValueFromField')->willReturnCallback(static fn(string $enum, string $value) => strtoupper($value));

        return new AttributesListFromDocuments($this->documents, $enums, $this->mode, $this->strict);
    }

    private function resolve(AttributesListFromDocuments $plugin, array $args, callable $core): array
    {
        return $plugin->aroundResolve(
            $this->createMock(AttributesList::class),
            \Closure::fromCallable($core),
            $this->createMock(Field::class),
            $this->context,
            $this->createMock(ResolveInfo::class),
            null,
            $args
        );
    }

    public function testTheFilteredListComesFromTheDocumentsInAttributeIdOrder(): void
    {
        $this->documents->expects(self::once())->method('all')->with('default')->willReturn(self::DOCUMENTS);

        $result = $this->resolve($this->plugin(), ['entityType' => 'CATALOG_PRODUCT', 'filters' => ['is_searchable' => true, 'is_filterable' => true]], static fn() => self::fail('core must not run'));

        self::assertSame('CATALOG_PRODUCT', $result['entity_type']);
        self::assertSame([], $result['errors']);
        self::assertSame(['color'], array_column($result['items'], 'code'));
        $color = $result['items'][0];
        self::assertSame(93, $color['id']);
        self::assertSame('Colour', $color['label']);
        self::assertSame(3, $color['sort_order']);
        self::assertSame('SELECT', $color['frontend_input']);
        self::assertSame([
            ['label' => 'Red', 'value' => '5', 'is_default' => true],
            ['label' => 'Blue', 'value' => '6', 'is_default' => false],
        ], $color['options']);
        self::assertTrue($color['is_filterable']);
        self::assertTrue($color['is_searchable']);
        self::assertTrue($color['used_in_product_listing']);
        self::assertFalse($color['is_visible_on_front']);
        self::assertFalse($color['is_comparable']);
        self::assertSame(['SIMPLE', 'CONFIGURABLE'], $color['apply_to']);
        self::assertSame('VISUAL', $color['swatch_input_type']);
        self::assertSame('1', $color['update_product_preview_image']);
    }

    public function testWithoutFiltersEveryVisibleNonStaticAttributeIsListed(): void
    {
        $this->documents->method('all')->willReturn(self::DOCUMENTS);

        $result = $this->resolve($this->plugin(), ['entityType' => 'CATALOG_PRODUCT', 'filters' => []], static fn() => self::fail('core must not run'));

        self::assertSame(['name', 'color', 'size'], array_column($result['items'], 'code'));
        $name = $result['items'][0];
        self::assertSame('TEXT', $name['frontend_input']);
        self::assertSame('validate-length maximum-length-255', $name['frontend_class']);
        self::assertNull($name['default_value']);
        self::assertTrue($name['is_required']);
        self::assertSame([], $name['options']);
        self::assertNull($name['apply_to']);
        self::assertFalse($result['items'][2]['is_filterable']);
    }

    public function testCoreAnswersOtherEntitiesAndTheCoreMode(): void
    {
        $this->documents->method('all')->willReturn([]);
        $core = static fn() => ['items' => ['core'], 'entity_type' => 'X', 'errors' => []];

        self::assertSame(['core'], $this->resolve($this->plugin(), ['entityType' => 'CATALOG_CATEGORY', 'filters' => []], $core)['items']);

        $mode = $this->createMock(Mode::class);
        $mode->method('documents')->willReturn(false);
        $plugin = new AttributesListFromDocuments($this->documents, $this->createMock(EnumLookup::class), $mode, $this->strict);
        self::assertSame(['core'], $this->resolve($plugin, ['entityType' => 'CATALOG_PRODUCT', 'filters' => []], $core)['items']);
    }

    public function testAnUnsupportedFilterFails(): void
    {
        $this->expectException(DocumentReadException::class);
        $this->resolve($this->plugin(), ['entityType' => 'CATALOG_PRODUCT', 'filters' => ['is_global' => true]], static fn() => ['core']);
    }

    public function testMissingDocumentsFail(): void
    {
        $this->documents->method('all')->willReturn([]);
        $this->expectException(DocumentReadException::class);
        $this->resolve($this->plugin(), ['entityType' => 'CATALOG_PRODUCT', 'filters' => []], static fn() => ['core']);
    }
}
