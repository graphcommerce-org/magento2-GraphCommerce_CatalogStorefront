<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Read\ProductAttributeOptions;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class ProductAttributeOptionsTest extends TestCase
{
    public function testOptionlessBooleanAndPriceMetadataSurviveAndNullOptionsAreNotEmitted(): void
    {
        $rows = [
            $this->row(90, 'sale', 'boolean', null, null),
            $this->row(91, 'special_price', 'price', null, null),
            $this->row(147, 'material', 'multiselect', 35, 'Leather'),
            $this->row(147, 'material', 'multiselect', 146, 'Jersey'),
        ];
        [$reader, $query] = $this->reader($rows);

        $result = $reader->matching([35, 146], 1, ['sale', 'special_price', 'material']);
        self::assertSame([
            'sale' => $this->expected(90, 'sale', 'boolean'),
            'special_price' => $this->expected(91, 'special_price', 'price'),
            'material' => $this->expected(147, 'material', 'multiselect', ['35' => 'Leather', '146' => 'Jersey']),
        ], $result);
        self::assertSame(
            ['attribute_label', 'attribute_configuration', 'options', 'option_value', 'option_value_store'],
            $query->joinAliases
        );
        self::assertStringContainsString('attribute_label.store_id = ?', $query->joinConditions['attribute_label']);
        self::assertStringContainsString('option_value.store_id = 0', $query->joinConditions['option_value']);
        self::assertStringContainsString('option_value_store.store_id = ?', $query->joinConditions['option_value_store']);
        self::assertStringContainsString('a.entity_type_id = ?', $query->where[0]);
        self::assertStringContainsString('option_value.option_id IN (?)', $query->where[1]);
        self::assertStringContainsString('attribute_configuration.is_filterable = 2', $query->where[1]);
        self::assertStringContainsString("a.frontend_input IN ('boolean', 'price')", $query->where[1]);
        self::assertSame(['options.sort_order ASC', 'options.option_id ASC'], $query->order);
    }

    public function testAllSkipsOptionlessRowsAndRetainsTheCanonicalDatabaseOrder(): void
    {
        [$reader] = $this->reader([
            $this->row(90, 'sale', 'boolean', null, null),
            $this->row(147, 'material', 'multiselect', 35, 'Leather'),
            $this->row(147, 'material', 'multiselect', 146, 'Jersey'),
        ]);

        self::assertSame([
            147 => [
                ['id' => '35', 'label' => 'Leather', 'sortOrder' => 4],
                ['id' => '146', 'label' => 'Jersey', 'sortOrder' => 4],
            ],
        ], $reader->all([90, 147], 1));
    }

    public function testStoreLabelOverridesTheDefaultAndAnEmptyStoreLabelFallsBack(): void
    {
        [$reader] = $this->reader([
            $this->row(147, 'material', 'multiselect', 35, 'Leather', 'Materiaal'),
            $this->row(148, 'fabric', 'select', 36, 'Mesh', ''),
        ]);

        $result = $reader->matching([35, 36], 1);
        self::assertSame('Materiaal', $result['material']['attribute_label']);
        self::assertSame('Fabric', $result['fabric']['attribute_label']);
    }

    private function reader(array $rows): array
    {
        $calls = (object)['joinAliases' => [], 'joinConditions' => [], 'where' => [], 'order' => []];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('joinLeft')->willReturnCallback(
            static function (array $name, string $condition) use ($select, $calls): Select {
                $alias = (string)array_key_first($name);
                $calls->joinAliases[] = $alias;
                $calls->joinConditions[$alias] = $condition;
                return $select;
            }
        );
        $select->method('where')->willReturnCallback(
            static function (string $condition) use ($select, $calls): Select {
                $calls->where[] = $condition;
                return $select;
            }
        );
        $select->method('order')->willReturnCallback(
            static function (array $order) use ($select, $calls): Select {
                $calls->order = $order;
                return $select;
            }
        );
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteInto')->willReturnCallback(static fn(string $condition): string => $condition);
        $connection->method('getCheckSql')->willReturn('resolved_option_label');
        $connection->method('fetchAll')->with($select)->willReturn($rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn(string $table): string => $table);
        $entity = $this->createMock(Type::class);
        $entity->method('getId')->willReturn(4);
        $eav = $this->createMock(EavConfig::class);
        $eav->method('getEntityType')->with('catalog_product')->willReturn($entity);

        return [new ProductAttributeOptions($resource, $eav), $calls];
    }

    private function row(
        int $attributeId,
        string $code,
        string $type,
        ?int $optionId,
        ?string $optionLabel,
        ?string $storeLabel = null
    ): array
    {
        return [
            'attribute_id' => $attributeId,
            'attribute_code' => $code,
            'attribute_label' => ucfirst(str_replace('_', ' ', $code)),
            'attribute_store_label' => $storeLabel,
            'attribute_type' => $type,
            'position' => '0',
            'is_filterable' => '1',
            'option_id' => $optionId,
            'option_label' => $optionLabel,
            'sort_order' => 4,
        ];
    }

    private function expected(int $attributeId, string $code, string $type, array $options = []): array
    {
        return [
            'attribute_id' => (string)$attributeId,
            'attribute_code' => $code,
            'attribute_label' => ucfirst(str_replace('_', ' ', $code)),
            'attribute_type' => $type,
            'position' => '0',
            'is_filterable' => 1,
            'options' => $options,
        ];
    }
}
