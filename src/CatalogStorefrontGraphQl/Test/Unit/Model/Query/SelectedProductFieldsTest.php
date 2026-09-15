<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model\Query;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Query\SelectedProductFields;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use PHPUnit\Framework\TestCase;

class SelectedProductFieldsTest extends TestCase
{
    public function testEveryProductNodeBelowTheFieldContributesItsFields(): void
    {
        $info = $this->createMock(ResolveInfo::class);
        $info->method('getFieldSelection')->willReturn([
            'quantity' => true,
            'prices' => ['price' => true],
            'product' => ['sku' => true, 'price_range' => ['minimum_price' => true]],
            'configured_variant' => ['sku' => true, 'thumbnail' => ['url' => true]],
        ]);

        self::assertSame(
            ['sku', 'price_range', 'thumbnail'],
            (new SelectedProductFields())->of($info)
        );
    }

    public function testAListOfItemsReachesTheProductOfEveryItem(): void
    {
        $info = $this->createMock(ResolveInfo::class);
        $info->method('getFieldSelection')->willReturn([
            'items' => ['id' => true, 'product' => ['name' => true, 'categories' => ['uid' => true]]],
            'page_info' => ['page_size' => true],
        ]);

        self::assertSame(['name', 'categories'], (new SelectedProductFields())->of($info));
    }

    public function testAQueryWithoutAProductNodeSelectsNothing(): void
    {
        $info = $this->createMock(ResolveInfo::class);
        $info->method('getFieldSelection')->willReturn(['items' => ['id' => true]]);

        self::assertSame([], (new SelectedProductFields())->of($info));
    }
}
