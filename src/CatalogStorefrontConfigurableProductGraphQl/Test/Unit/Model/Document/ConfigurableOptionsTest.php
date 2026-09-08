<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Test\Unit\Model\Document;

use GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Model\Document\Field\ConfigurableOptions as Field;
use GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Model\Read\ConfigurableOptions as Read;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Swatches\Helper\Media as SwatchMedia;
use PHPUnit\Framework\TestCase;

/**
 * The document contract: a feed row in, the compact field out, and the
 * expansion answers the configurable_options shape.
 */
class ConfigurableOptionsTest extends TestCase
{
    private function row(): array
    {
        $uid = new Uid();

        return [
            'productId' => 494103,
            'type' => 'configurable',
            'optionsV2' => [
                ['type' => 'custom', 'id' => 'engraving'],
                [
                    'type' => 'configurable',
                    'id' => 'size',
                    'superAttributeId' => 401,
                    'label' => 'Size',
                    'sortOrder' => 1,
                    'values' => [
                        ['id' => $uid->encode('configurable/144/5'), 'label' => 'S', 'textSwatchValue' => 'S'],
                    ],
                ],
                [
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

    private function document(): array
    {
        $swatchMedia = $this->createMock(SwatchMedia::class);
        $swatchMedia->method('getSwatchMediaUrl')->willReturn('https://shop.test/media/attribute/swatch');

        return (new Field(new Uid(), $swatchMedia))->add('default', [$this->row()])[0];
    }

    public function testStoresTheOptionsCompactInPositionOrderAndDropsTheRawEntries(): void
    {
        $document = $this->document();

        $this->assertSame([['type' => 'custom', 'id' => 'engraving']], $document['optionsV2']);
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
                'useDefault' => false,
                'values' => [['index' => 5, 'label' => 'S', 'swatch' => ['type' => 0, 'value' => 'S']]],
            ],
        ], $document['configurableOptions']);
    }

    public function testExpandsToTheResponseShape(): void
    {
        $uid = new Uid();
        $options = (new Read($uid))->expand($this->document());

        $this->assertCount(2, $options);
        $this->assertSame([
            'id' => 400,
            'use_default' => true,
            'uid' => $uid->encode('configurable/494103/93'),
            'attribute_id' => '93',
            'attribute_id_v2' => 93,
            'attribute_uid' => $uid->encode('93'),
            'attribute_code' => 'color',
            'label' => 'Colour',
            'position' => 0,
            'product_id' => 494103,
            'product_uid' => $uid->encode('494103'),
        ], array_diff_key($options[0], ['values' => null]));
        $this->assertSame([
            'value_index' => '1',
            'label' => 'Rood',
            'default_label' => 'Red',
            'store_label' => 'Red',
            'use_default_value' => true,
            'attribute_id' => '93',
            PrefillerInterface::KEY => [
                'uid' => $uid->encode('configurable/93/1'),
                'swatch_data' => ['type' => 1, 'value' => '#ff0000'],
            ],
        ], $options[0]['values'][0]);
        $this->assertSame('Plain', $options[0]['values'][2]['default_label']);
        $this->assertNull($options[0]['values'][2][PrefillerInterface::KEY]['swatch_data']);
    }

    public function testAnswersNullWithoutTheField(): void
    {
        $this->assertNull((new Read(new Uid()))->expand(['productId' => 1]));
    }
}
