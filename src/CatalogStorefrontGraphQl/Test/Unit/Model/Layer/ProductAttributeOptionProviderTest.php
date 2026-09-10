<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model\Layer;

use GraphCommerce\CatalogStorefront\Model\Read\ProductAttributeOptions;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Layer\ProductAttributeOptionProvider;
use PHPUnit\Framework\TestCase;

class ProductAttributeOptionProviderTest extends TestCase
{
    public function testItKeepsTheCoreContractAndDelegatesToTheProductScopedRead(): void
    {
        $options = $this->createMock(ProductAttributeOptions::class);
        $expected = [
            'material' => [
                'attribute_id' => '147',
                'options' => ['35' => 'Leather', '146' => 'Jersey'],
            ],
        ];
        $options->expects(self::once())->method('matching')
            ->with([35, 146], 1, ['material'])
            ->willReturn($expected);

        self::assertSame(
            $expected,
            (new ProductAttributeOptionProvider($options))->getOptions([35, 146], 1, ['material'])
        );
    }
}
