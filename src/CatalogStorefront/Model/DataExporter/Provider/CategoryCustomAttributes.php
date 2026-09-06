<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Catalog\Model\Category;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Exports per category and store view the raw value of every category
 * attribute that has one, the store view value over the default one, as a
 * category load puts them on the model: the fields GraphQL adds for the
 * category attributes read these values as they are.
 */
class CategoryCustomAttributes
{
    private const BACKEND_TYPES = ['varchar', 'int', 'decimal', 'datetime', 'text'];

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
        $output = [];
        foreach ($idsByStore as $storeViewCode => $ids) {
            $storeId = (int)$this->storeManager->getStore($storeViewCode)->getId();
            $rows = [];
            foreach (self::BACKEND_TYPES as $backendType) {
                $select = $connection->select()
                    ->from(['value' => $this->resourceConnection->getTableName('catalog_category_entity_' . $backendType)], ['entity_id', 'value'])
                    ->join(
                        ['attribute' => $this->resourceConnection->getTableName('eav_attribute')],
                        'attribute.attribute_id = value.attribute_id',
                        ['attribute_code']
                    )
                    ->join(
                        ['type' => $this->resourceConnection->getTableName('eav_entity_type')],
                        $connection->quoteInto('type.entity_type_id = attribute.entity_type_id AND type.entity_type_code = ?', Category::ENTITY),
                        []
                    )
                    ->where('value.entity_id IN (?)', array_unique($ids))
                    ->where('value.store_id IN (?)', [0, $storeId])
                    ->order('value.store_id ASC');
                foreach ($connection->fetchAll($select) as $row) {
                    $rows[(int)$row['entity_id']][$row['attribute_code']] = $row['value'];
                }
            }
            foreach ($rows as $id => $attributes) {
                foreach ($attributes as $code => $value) {
                    $output[$storeViewCode . '_' . $id . '_' . $code] = [
                        'categoryId' => (string)$id,
                        'storeViewCode' => $storeViewCode,
                        'customAttributes' => ['attributeCode' => $code, 'value' => (string)$value],
                    ];
                }
            }
        }

        return $output;
    }
}
