<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontSearch\Model\Config;
use GraphCommerce\CatalogStorefrontSearch\Plugin\EntityIdField;
use GraphCommerce\CatalogStorefrontSearch\Plugin\EntityIdSort;
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

        $config = $this->createMock(Config::class);
        $config->method('entityIdSort')->willReturnOnConsecutiveCalls(true, false);
        $script = ['_script' => ['source' => 'Long.parseLong(doc[\'_id\'].value)']];
        $plugin = new EntityIdSort($config);
        $subject = $this->createMock(EntityId::class);
        $attribute = $this->createMock(AttributeAdapter::class);

        self::assertSame(['entity_id' => ['order' => 'desc', 'unmapped_type' => 'integer']], $plugin->afterBuild($subject, $script, $attribute, 'desc'));
        self::assertSame($script, $plugin->afterBuild($subject, $script, $attribute, 'desc'));
    }
}
