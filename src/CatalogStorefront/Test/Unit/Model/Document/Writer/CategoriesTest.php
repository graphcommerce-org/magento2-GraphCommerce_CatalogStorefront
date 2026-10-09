<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Writer\Categories;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use PHPUnit\Framework\TestCase;

class CategoriesTest extends TestCase
{
    public function testTheCategoryOwnValuesReplaceTheValuesTheFeedDerivesFromItsAncestors(): void
    {
        $customAttributes = [
            ['attributeCode' => 'is_active', 'value' => '1'],
            ['attributeCode' => 'include_in_menu', 'value' => '1'],
        ];
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects(self::once())->method('upsert')->with('category', 'default', [
            10 => ['categoryId' => '10', 'storeViewCode' => 'default', 'isActive' => 1, 'includeInMenu' => 1, 'customAttributes' => $customAttributes],
            11 => ['categoryId' => '11', 'storeViewCode' => 'default', 'isActive' => 0, 'includeInMenu' => 0],
        ]);
        $storage->expects(self::once())->method('delete')->with('category', 'default', [12]);

        (new Categories($storage))->write([
            ['categoryId' => '10', 'storeViewCode' => 'default', 'isActive' => 0, 'includeInMenu' => 0, 'customAttributes' => $customAttributes],
            ['categoryId' => '11', 'storeViewCode' => 'default', 'isActive' => 0, 'includeInMenu' => 0],
            ['categoryId' => '12', 'storeViewCode' => 'default', 'deleted' => true],
        ]);
    }
}
