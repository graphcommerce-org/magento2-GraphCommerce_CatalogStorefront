<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Read\CategoryData;
use PHPUnit\Framework\TestCase;

class CategoryDataTest extends TestCase
{
    public function testTheCustomAttributesAndTheTreeFieldsBecomeModelData(): void
    {
        $data = (new CategoryData())->fromDocument([
            'categoryId' => '10',
            'parentId' => '34',
            'name' => 'Hurley',
            'path' => '1/2/34/10',
            'level' => 3,
            'children' => ['11', '12'],
            'urlPath' => 'clubkleding/hurley',
            'customAttributes' => [
                ['attributeCode' => 'custom_use_parent_settings', 'value' => '1'],
                ['attributeCode' => 'page_layout', 'value' => '2columns-left'],
            ],
        ]);

        $this->assertSame('10', $data['entity_id']);
        $this->assertSame('34', $data['parent_id']);
        $this->assertSame('1', $data['custom_use_parent_settings']);
        $this->assertSame('2columns-left', $data['page_layout']);
        $this->assertSame('2', $data['children_count']);
        $this->assertSame('clubkleding/hurley', $data['url_path']);
        $this->assertNull($data['description']);
    }

    public function testTheRootsEmptyUrlPathIsNull(): void
    {
        $this->assertNull((new CategoryData())->fromDocument(['urlPath' => ''])['url_path']);
    }
}
