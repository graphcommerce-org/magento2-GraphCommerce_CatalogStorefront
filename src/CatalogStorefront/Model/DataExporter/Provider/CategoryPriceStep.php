<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Feed provider of a category's layered navigation price step
 * (`filter_price_range`) in the store view's scope; the price facet reads it
 * from the current category and the categories feed does not carry it.
 */
class CategoryPriceStep
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
            $idsByStore[$value['storeViewCode']][] = (int)$value['categoryId'];
        }
        $connection = $this->resourceConnection->getConnection();
        $attributeId = (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'filter_price_range')
                ->where('entity_type_id = ?', 3)
        );
        $output = [];
        foreach ($idsByStore as $storeViewCode => $ids) {
            $storeId = (int)$this->storeManager->getStore($storeViewCode)->getId();
            $steps = [];
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_category_entity_decimal'), ['entity_id', 'store_id', 'value'])
                ->where('attribute_id = ?', $attributeId)
                ->where('entity_id IN (?)', $ids)
                ->where('store_id IN (?)', [0, $storeId]);
            foreach ($connection->fetchAll($select) as $row) {
                $id = (int)$row['entity_id'];
                if ((int)$row['store_id'] === $storeId || !isset($steps[$id])) {
                    $steps[$id] = $row['value'];
                }
            }
            foreach ($ids as $id) {
                $output[$storeViewCode . '_' . $id] = [
                    'categoryId' => (string)$id,
                    'storeViewCode' => $storeViewCode,
                    'filterPriceRange' => isset($steps[$id]) ? (string)$steps[$id] : null,
                ];
            }
        }

        return $output;
    }
}
