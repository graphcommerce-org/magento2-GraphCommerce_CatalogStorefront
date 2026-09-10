<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read\ConfigurableOptions;
use PHPUnit\Framework\TestCase;

class ConfigurableOptionsTest extends TestCase
{
    private ConfigurableOptions $options;

    protected function setUp(): void
    {
        $this->options = new ConfigurableOptions();
    }

    /**
     * One option as the products writer stores it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function document(array $overrides = []): array
    {
        return [
            'productId' => 42,
            'configurableOptions' => [
                [
                    'id' => 400,
                    'attribute' => 93,
                    'code' => 'color',
                    'label' => 'Kleur',
                    'position' => 0,
                    'useDefault' => true,
                    'values' => [
                        ['index' => 1, 'label' => 'Rood', 'defaultLabel' => 'Red', 'swatch' => ['type' => 1, 'value' => '#ff0000']],
                    ],
                ],
            ],
        ] + $overrides;
    }

    public function testExpandsAnOptionIntoASuperAttributeRow(): void
    {
        $rows = $this->options->attributes($this->document());

        $this->assertCount(1, $rows);
        $this->assertSame('93', $rows[0]['attribute_id'], 'ids are the strings the database returns');
        $this->assertSame('color', $rows[0]['attribute_code']);
        $this->assertSame(400, $rows[0]['id']);
        $this->assertSame(42, $rows[0]['product_id']);
        $this->assertTrue($rows[0]['use_default']);
    }

    public function testAValueCarriesBothLabels(): void
    {
        // The admin label is the default and the store label; the store view label is its own.
        $value = $this->options->attributes($this->document())[0]['values'][0];

        $this->assertSame('1', $value['value_index']);
        $this->assertSame('Rood', $value['label']);
        $this->assertSame('Red', $value['default_label']);
        $this->assertSame('Red', $value['store_label']);
        $this->assertSame(['type' => 1, 'value' => '#ff0000'], $value['swatch']);
    }

    public function testNoConfigurableOptionsIsNotAnAnswer(): void
    {
        $this->assertNull($this->options->attributes(['productId' => 42]));
    }

    public function testNoProductIdIsNotAnAnswer(): void
    {
        $this->assertNull($this->options->attributes(['configurableOptions' => []]));
    }

    public function testAnOptionWithoutAnAttributeIdIsNotAnAnswer(): void
    {
        // The caller takes the whole set from core: a short option list renders a product that
        // cannot be bought.
        $document = $this->document();
        unset($document['configurableOptions'][0]['attribute']);

        $this->assertNull($this->options->attributes($document));
    }

    public function testAValueWithoutAnIndexIsNotAnAnswer(): void
    {
        $document = $this->document();
        unset($document['configurableOptions'][0]['values'][0]['index']);

        $this->assertNull($this->options->attributes($document));
    }

    public function testAnOptionWithoutValuesIsStillAnAnswer(): void
    {
        // An option list is short only where a value cannot be built, not where none is stored.
        $document = $this->document();
        unset($document['configurableOptions'][0]['values']);

        $rows = $this->options->attributes($document);

        $this->assertSame([], $rows[0]['values']);
    }
}
