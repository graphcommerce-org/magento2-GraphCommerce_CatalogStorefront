<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\DataExporter\Provider\ConfigurableOptions as Provider;
use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read\ConfigurableOptions as Read;
use Magento\ConfigurableProductDataExporter\Model\Provider\Product\Options;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Swatches\Helper\Media as SwatchMedia;
use PHPUnit\Framework\TestCase;

/**
 * The document contract: the exporter's own option entries in, the compact
 * field out one row per option, and the expansion answers the
 * configurable_options shape.
 */
class ConfigurableOptionsTest extends TestCase
{
    private function rawOptions(): array
    {
        $uid = new Uid();

        return [
            [
                'productId' => 494103,
                'storeViewCode' => 'default',
                'optionsV2' => [
                    'type' => 'configurable',
                    'id' => 'size',
                    'superAttributeId' => 401,
                    'label' => 'Size',
                    'sortOrder' => 1,
                    'values' => [
                        ['id' => $uid->encode('configurable/144/5'), 'label' => 'S', 'textSwatchValue' => 'S'],
                    ],
                ],
            ],
            [
                'productId' => 494103,
                'storeViewCode' => 'default',
                'optionsV2' => [
                    'type' => 'configurable',
                    'id' => 'color',
                    'superAttributeId' => 400,
                    'label' => 'Colour',
                    'sortOrder' => 0,
                    'useDefault' => true,
                    'values' => [
                        ['id' => $uid->encode('configurable/93/1'), 'label' => 'Rood', 'defaultLabel' => 'Red', 'colorHex' => '#ff0000'],
                        ['id' => $uid->encode('configurable/93/2'), 'label' => 'Blue', 'imageUrl' => 'https://shop.test/media/attribute/swatch/b.jpg'],
                        ['id' => $uid->encode('configurable/93/3'), 'label' => 'Plain'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array[] the configurableOptions entries of the one product, in the order the row keeps them
     */
    private function options(): array
    {
        $options = $this->createMock(Options::class);
        $options->method('get')->willReturn($this->rawOptions());
        $swatchMedia = $this->createMock(SwatchMedia::class);
        $swatchMedia->method('getSwatchMediaUrl')->willReturn('https://shop.test/media/attribute/swatch');
        $swatchMedia->expects(self::once())->method('getSwatchAttributeImage')->with('swatch_thumb', '/b.jpg');

        $output = (new Provider($options, new Uid(), $swatchMedia))->get([
            ['productId' => 494103, 'storeViewCode' => 'default', 'type' => 'configurable'],
            ['productId' => 7, 'storeViewCode' => 'default', 'type' => 'simple'],
        ]);

        self::assertSame(['default_494103_93', 'default_494103_144'], array_keys($output));

        return array_column($output, 'configurableOptions');
    }

    public function testStoresTheOptionsCompactInPositionOrder(): void
    {
        $this->assertSame([
            [
                'id' => 400,
                'attribute' => 93,
                'code' => 'color',
                'label' => 'Colour',
                'position' => 0,
                'useDefault' => true,
                'values' => [
                    ['index' => 1, 'label' => 'Rood', 'defaultLabel' => 'Red', 'swatch' => ['type' => 1, 'value' => '#ff0000']],
                    ['index' => 2, 'label' => 'Blue', 'swatch' => ['type' => 2, 'value' => '/b.jpg']],
                    ['index' => 3, 'label' => 'Plain'],
                ],
            ],
            [
                'id' => 401,
                'attribute' => 144,
                'code' => 'size',
                'label' => 'Size',
                'position' => 1,
                'useDefault' => null,
                'values' => [['index' => 5, 'label' => 'S', 'swatch' => ['type' => 0, 'value' => 'S']]],
            ],
        ], $this->options());
    }

    public function testExpandsToTheSuperAttributeRows(): void
    {
        $options = (new Read())->attributes(['productId' => 494103, 'configurableOptions' => $this->options()]);

        $this->assertCount(2, $options);
        $this->assertNull($options[1]['use_default'], 'a super attribute without a label row answers null, as core does');
        $this->assertSame([
            'id' => 400,
            'use_default' => true,
            'attribute_id' => '93',
            'attribute_code' => 'color',
            'label' => 'Colour',
            'position' => 0,
            'product_id' => 494103,
        ], array_diff_key($options[0], ['values' => null]));
        $this->assertSame([
            'value_index' => '1',
            'label' => 'Rood',
            'default_label' => 'Red',
            'store_label' => 'Red',
            'use_default_value' => true,
            'attribute_id' => '93',
            'swatch' => ['type' => 1, 'value' => '#ff0000'],
        ], $options[0]['values'][0]);
        $this->assertSame('Plain', $options[0]['values'][2]['default_label']);
        $this->assertNull($options[0]['values'][2]['swatch']);
    }

    public function testAnswersNullWithoutTheField(): void
    {
        $this->assertNull((new Read())->attributes(['productId' => 1]));
    }
}
