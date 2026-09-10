<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * Reads product attribute options with the scope and ordering used by catalog facets.
 *
 * Core's GraphQL provider joins every EAV entity type and orders only by sort_order.
 * Attribute codes and option sort positions are not globally unique, so that can mix
 * another entity's option into a product facet and leaves tied options dependent on
 * the database plan. This read keeps the merchant order and makes its tie explicit.
 */
class ProductAttributeOptions
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EavConfig $eavConfig,
    ) {
    }

    /**
     * The shape Magento's layered-navigation AttributeOptionProvider returns.
     */
    public function matching(array $optionIds, ?int $storeId, array $attributeCodes = []): array
    {
        if (!$optionIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $this->select((int)($storeId ?? 0));
        $conditions = [
            $connection->quoteInto('option_value.option_id IN (?)', $optionIds),
            'attribute_configuration.is_filterable = 2',
        ];
        if ($attributeCodes) {
            $conditions[] = $connection->quoteInto(
                "(a.attribute_code IN (?) AND a.frontend_input IN ('boolean', 'price'))",
                $attributeCodes
            );
        }
        $select->where('(' . implode(' OR ', $conditions) . ')');

        return $this->format($connection->fetchAll($select));
    }

    /**
     * Every table-backed option for the requested product attributes, by attribute id.
     *
     * @return array<int, array<int, array{id: string, label: string, sortOrder: int}>>
     */
    public function all(array $attributeIds, int $storeId): array
    {
        $attributeIds = array_values(array_unique(array_map('intval', $attributeIds)));
        if (!$attributeIds) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll($this->select($storeId)->where('a.attribute_id IN (?)', $attributeIds));
        $result = [];
        foreach ($rows as $row) {
            if (empty($row['option_id'])) {
                continue;
            }
            $result[(int)$row['attribute_id']][] = [
                'id' => (string)$row['option_id'],
                'label' => (string)$row['option_label'],
                'sortOrder' => (int)$row['sort_order'],
            ];
        }

        return $result;
    }

    private function select(int $storeId): Select
    {
        $connection = $this->resourceConnection->getConnection();
        $productEntityTypeId = (int)$this->eavConfig->getEntityType(Product::ENTITY)->getId();

        return $connection->select()
            ->from(['a' => $this->resourceConnection->getTableName('eav_attribute')], [
                'attribute_id' => 'a.attribute_id',
                'attribute_code' => 'a.attribute_code',
                'attribute_label' => 'a.frontend_label',
                'attribute_type' => 'a.frontend_input',
                'position' => 'attribute_configuration.position',
                'is_filterable' => 'attribute_configuration.is_filterable',
            ])
            ->joinLeft(
                ['attribute_label' => $this->resourceConnection->getTableName('eav_attribute_label')],
                $connection->quoteInto('a.attribute_id = attribute_label.attribute_id AND attribute_label.store_id = ?', $storeId),
                ['attribute_store_label' => 'attribute_label.value']
            )
            ->joinLeft(
                ['attribute_configuration' => $this->resourceConnection->getTableName('catalog_eav_attribute')],
                'a.attribute_id = attribute_configuration.attribute_id',
                []
            )
            ->joinLeft(
                ['options' => $this->resourceConnection->getTableName('eav_attribute_option')],
                'a.attribute_id = options.attribute_id',
                ['sort_order' => 'options.sort_order']
            )
            ->joinLeft(
                ['option_value' => $this->resourceConnection->getTableName('eav_attribute_option_value')],
                'options.option_id = option_value.option_id AND option_value.store_id = 0',
                ['option_id' => 'option_value.option_id']
            )
            ->joinLeft(
                ['option_value_store' => $this->resourceConnection->getTableName('eav_attribute_option_value')],
                $connection->quoteInto('options.option_id = option_value_store.option_id AND option_value_store.store_id = ?', $storeId),
                ['option_label' => $connection->getCheckSql(
                    'option_value_store.value_id > 0',
                    'option_value_store.value',
                    'option_value.value'
                )]
            )
            ->where('a.entity_type_id = ?', $productEntityTypeId)
            ->order(['options.sort_order ' . Select::SQL_ASC, 'options.option_id ' . Select::SQL_ASC]);
    }

    private function format(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $code = (string)$row['attribute_code'];
            $result[$code] ??= [
                'attribute_id' => (string)$row['attribute_id'],
                'attribute_code' => $code,
                'attribute_label' => $row['attribute_store_label'] ?: $row['attribute_label'],
                'attribute_type' => $row['attribute_type'],
                'position' => $row['position'],
                'is_filterable' => (int)$row['is_filterable'],
                'options' => [],
            ];
            if (!empty($row['option_id'])) {
                $result[$code]['options'][(string)$row['option_id']] = $row['option_label'];
            }
        }

        return $result;
    }
}
