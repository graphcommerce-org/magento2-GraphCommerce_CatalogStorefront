<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Feed provider of an attribute's options with their store view labels in
 * sort order, one feed row per option as the exporter assembles a repeated
 * field; the exporter's attribute metadata carries no options.
 */
class AttributeOptionsProvider
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function get(array $values): array
    {
        $idsByStore = [];
        foreach ($values as $value) {
            $idsByStore[$value['storeViewCode']][] = (int)$value['id'];
        }
        $connection = $this->resourceConnection->getConnection();
        $output = [];
        foreach ($idsByStore as $storeViewCode => $ids) {
            $storeId = (int)$this->storeManager->getStore($storeViewCode)->getId();
            $select = $connection->select()
                ->from(['option' => $this->resourceConnection->getTableName('eav_attribute_option')], ['attribute_id', 'option_id'])
                ->join(
                    ['default_value' => $this->resourceConnection->getTableName('eav_attribute_option_value')],
                    'default_value.option_id = option.option_id AND default_value.store_id = 0',
                    []
                )
                ->joinLeft(
                    ['store_value' => $this->resourceConnection->getTableName('eav_attribute_option_value')],
                    'store_value.option_id = option.option_id AND store_value.store_id = ' . $storeId,
                    ['label' => $connection->getCheckSql('store_value.value_id > 0', 'store_value.value', 'default_value.value')]
                )
                ->where('option.attribute_id IN (?)', $ids)
                ->order(['option.sort_order ASC', 'option.option_id ASC']);
            foreach ($connection->fetchAll($select) as $row) {
                $output[$storeViewCode . '_' . $row['attribute_id'] . '_' . $row['option_id']] = [
                    'id' => (string)$row['attribute_id'],
                    'storeViewCode' => $storeViewCode,
                    'options' => ['id' => (string)$row['option_id'], 'label' => (string)$row['label']],
                ];
            }
        }

        return $output;
    }
}
