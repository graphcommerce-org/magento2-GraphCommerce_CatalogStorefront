<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document\Field;

use GraphCommerce\CatalogStorefront\Model\Document\Field\TaxClassId;
use Magento\Framework\DataObject;
use Magento\Tax\Model\ResourceModel\TaxClass\Collection;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory;
use PHPUnit\Framework\TestCase;

class TaxClassIdTest extends TestCase
{
    public function testTakesTheIdFromTheAttributeElseFromTheName(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([
            new DataObject(['id' => 2, 'class_name' => 'Taxable Goods']),
        ]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $documents = (new TaxClassId($factory))->add('default', [
            1 => ['taxClassId' => 'Taxable Goods', 'customAttributes' => [['attributeCode' => 'tax_class_id', 'value' => '2']]],
            2 => ['taxClassId' => 'Taxable Goods'],
            3 => ['taxClassId' => 'no'],
            4 => ['customAttributes' => [['attributeCode' => 'tax_class_id', 'value' => 'Taxable Goods']]],
        ]);

        self::assertSame(
            [1 => 2, 2 => 2, 3 => null, 4 => 2],
            array_map(static fn(array $document) => $document['taxClassId'], $documents)
        );
    }
}
