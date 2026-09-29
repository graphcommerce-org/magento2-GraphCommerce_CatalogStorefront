<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use Psr\Log\LoggerInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Layer\AttributeOptionsFromDocuments;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\AttributeOptionProvider;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class AttributeOptionsFromDocumentsTest extends TestCase
{
    private MetadataDocumentStorageInterface $storage;
    private FacetDocuments $facets;
    private Strict $strict;
    private AttributeOptionsFromDocuments $plugin;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $this->facets = $this->createMock(FacetDocuments::class);
        $this->strict = new Strict($this->createStub(StorefrontKey::class), $this->createStub(LoggerInterface::class));
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(1)->willReturn($store);
        $mode = $this->createMock(Mode::class);
        $mode->method('documents')->willReturn(true);
        $this->plugin = new AttributeOptionsFromDocuments(
            $this->facets,
            $this->storage,
            $storeManager,
            $mode,
            $this->strict
        );
    }

    public function testTheCorePathRunsCore(): void
    {
        $mode = $this->createMock(Mode::class);
        $mode->method('documents')->willReturn(false);
        $this->storage->expects(self::never())->method('any');
        $plugin = new AttributeOptionsFromDocuments(
            $this->facets,
            $this->storage,
            $this->createMock(StoreManagerInterface::class),
            $mode,
            $this->strict
        );

        self::assertSame(['core'], $plugin->aroundGetOptions(
            $this->createMock(AttributeOptionProvider::class),
            static fn() => ['core'],
            [5],
            1
        ));
    }

    public function testOneQueryFetchesTheOwnersTheUnfilteredAndTheRequestedBooleanAttributes(): void
    {
        $this->storage->expects(self::once())->method('any')->with('attribute', 'default', [
            ['options.id' => [5, 6]],
            ['filterableMode' => 2],
            ['id' => ['color', 'new'], 'frontendInput' => ['boolean', 'price']],
        ])->willReturn([
            'color' => [
                'attributeId' => 93, 'label' => 'Color', 'frontendInput' => 'select', 'position' => 3, 'filterableMode' => 1,
                'options' => [['id' => 5, 'label' => 'Red'], ['id' => 7, 'label' => 'Blue']],
            ],
            'size' => [
                'attributeId' => 94, 'label' => 'Size', 'frontendInput' => 'select', 'filterableMode' => 2,
                'options' => [['id' => 8, 'label' => 'S'], ['id' => 6, 'label' => 'M']],
            ],
            'new' => ['attributeId' => 95, 'label' => 'New', 'frontendInput' => 'boolean', 'filterableMode' => 1, 'options' => []],
        ]);

        $result = $this->plugin->aroundGetOptions(
            $this->createMock(AttributeOptionProvider::class),
            static fn() => self::fail('core must not run'),
            [5, 6],
            1,
            ['color', 'new']
        );

        self::assertSame([
            'color' => [
                'attribute_id' => '93', 'attribute_code' => 'color', 'attribute_label' => 'Color', 'attribute_type' => 'select',
                'position' => '3', 'is_filterable' => 1, 'options' => ['5' => 'Red'],
            ],
            'size' => [
                'attribute_id' => '94', 'attribute_code' => 'size', 'attribute_label' => 'Size', 'attribute_type' => 'select',
                'position' => '0', 'is_filterable' => 2, 'options' => ['8' => 'S', '6' => 'M'],
            ],
            'new' => [
                'attribute_id' => '95', 'attribute_code' => 'new', 'attribute_label' => 'New', 'attribute_type' => 'boolean',
                'position' => '0', 'is_filterable' => 1, 'options' => [],
            ],
        ], $result);
    }

    public function testAPrimedFacetReadServesTheOptionsWithoutAQuery(): void
    {
        $this->facets->method('attributes')->with('default', [5], ['color'])->willReturn([
            'color' => ['attributeId' => 93, 'label' => 'Color', 'frontendInput' => 'select', 'filterableMode' => 1, 'options' => [['id' => 5, 'label' => 'Red']]],
            'size' => ['attributeId' => 94, 'label' => 'Size', 'frontendInput' => 'select', 'filterableMode' => 1, 'options' => [['id' => 8, 'label' => 'S']]],
        ]);
        $this->storage->expects(self::never())->method('any');

        $result = $this->plugin->aroundGetOptions($this->createMock(AttributeOptionProvider::class), static fn() => self::fail('core must not run'), [5], 1, ['color']);

        self::assertSame(['color'], array_keys($result));
        self::assertSame(['5' => 'Red'], $result['color']['options']);
    }

    public function testDocumentOrderWinsOverRequestedOrderForTiedOptions(): void
    {
        $this->facets->method('attributes')->willReturn([
            'material' => [
                'attributeId' => 147,
                'label' => 'Material',
                'frontendInput' => 'multiselect',
                'filterableMode' => 1,
                'options' => [
                    ['id' => 146, 'label' => 'Jersey', 'facetSortOrder' => 4],
                    ['id' => 35, 'label' => 'Leather', 'facetSortOrder' => 4],
                ],
            ],
        ]);

        $result = $this->plugin->aroundGetOptions(
            $this->createMock(AttributeOptionProvider::class),
            static fn() => self::fail('core must not run'),
            [146, 35],
            1,
            ['material']
        );

        self::assertSame([35, 146], array_keys($result['material']['options']));
    }

    public function testAProductOptionWithAnEmptyLabelIsStillListed(): void
    {
        $this->facets->method('attributes')->willReturn([
            'material' => [
                'attributeId' => 147,
                'label' => 'Material',
                'frontendInput' => 'select',
                'filterableMode' => 1,
                'options' => [['id' => 35, 'label' => '', 'facetSortOrder' => 4]],
            ],
        ]);

        $result = $this->plugin->aroundGetOptions(
            $this->createMock(AttributeOptionProvider::class),
            static fn() => self::fail('core must not run'),
            [35],
            1,
            ['material']
        );

        self::assertArrayHasKey(35, $result['material']['options']);
        self::assertSame('', $result['material']['options'][35]);
    }

    public function testAnEmptyStoreViewFails(): void
    {
        $this->storage->method('any')->willReturn([]);
        $this->storage->method('count')->willReturn(0);
        $this->expectException(DocumentReadException::class);
        $this->plugin->aroundGetOptions($this->createMock(AttributeOptionProvider::class), static fn() => ['core'], [5], 1);
    }

    public function testAnEmptyMatchReturnsNoOptions(): void
    {
        $this->storage->method('any')->willReturn([]);
        $this->storage->method('count')->willReturn(12);
        $subject = $this->createMock(AttributeOptionProvider::class);

        self::assertSame([], $this->plugin->aroundGetOptions($subject, static fn() => ['core'], [5], 1));
        self::assertSame([], $this->plugin->aroundGetOptions($subject, static fn() => ['core'], [], 1));
    }
}
