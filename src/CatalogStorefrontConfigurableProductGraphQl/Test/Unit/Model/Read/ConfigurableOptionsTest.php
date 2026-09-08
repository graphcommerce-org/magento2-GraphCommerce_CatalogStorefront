<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read\ConfigurableOptions as Attributes;
use GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Model\Read\ConfigurableOptions;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\Framework\GraphQl\Query\Uid;
use PHPUnit\Framework\TestCase;

class ConfigurableOptionsTest extends TestCase
{
    public function testAddsTheUidsAndPrefillsTheValueUidAndSwatch(): void
    {
        $uid = new Uid();
        $document = [
            'productId' => 494103,
            'configurableOptions' => [[
                'id' => 400,
                'attribute' => 93,
                'code' => 'color',
                'label' => 'Colour',
                'position' => 0,
                'useDefault' => false,
                'values' => [['index' => 1, 'label' => 'Red', 'swatch' => ['type' => 1, 'value' => '#ff0000']]],
            ]],
        ];
        $options = (new ConfigurableOptions(new Attributes(), $uid))->expand($document);

        $this->assertSame([
            'id' => 400,
            'use_default' => false,
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
            'label' => 'Red',
            'default_label' => 'Red',
            'store_label' => 'Red',
            'use_default_value' => true,
            'attribute_id' => '93',
            PrefillerInterface::KEY => ['uid' => $uid->encode('configurable/93/1'), 'swatch_data' => ['type' => 1, 'value' => '#ff0000']],
        ], $options[0]['values'][0]);
        $this->assertNull((new ConfigurableOptions(new Attributes(), $uid))->expand(['productId' => 1]));
    }
}
