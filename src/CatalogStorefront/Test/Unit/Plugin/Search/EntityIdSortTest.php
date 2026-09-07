<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Plugin\Search;

use GraphCommerce\CatalogStorefront\Plugin\Search\EntityIdField;
use GraphCommerce\CatalogStorefront\Plugin\Search\EntityIdSort;
use Magento\Elasticsearch\Model\Adapter\BatchDataMapper\ProductDataMapper;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeAdapter;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort\EntityId;
use PHPUnit\Framework\TestCase;

class EntityIdSortTest extends TestCase
{
    public function testTheTieBreakSortsOnTheFieldTheDocumentCarries(): void
    {
        $documents = (new EntityIdField())->afterMap($this->createMock(ProductDataMapper::class), [
            42 => ['sku' => 'A', 'store_id' => 1],
            '43' => ['sku' => 'B', 'store_id' => 1],
        ]);
        self::assertSame(42, $documents[42]['entity_id']);
        self::assertSame(43, $documents[43]['entity_id']);

        $sort = (new EntityIdSort())->afterBuild(
            $this->createMock(EntityId::class),
            ['_script' => ['source' => 'Long.parseLong(doc[\'_id\'].value)']],
            $this->createMock(AttributeAdapter::class),
            'desc'
        );
        self::assertSame(['entity_id' => ['order' => 'desc', 'unmapped_type' => 'integer']], $sort);
    }
}
