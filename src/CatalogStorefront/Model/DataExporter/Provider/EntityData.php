<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

class EntityData
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function get(array $values): array
    {
        $ids = array_unique(array_map(static fn(array $value) => (int)$value['productId'], $values));
        if (!$ids) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $entities = $connection->fetchAssoc(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id', 'attribute_set_id', 'has_options', 'required_options'])
                ->where('entity_id IN (?)', $ids)
        );
        $output = [];
        foreach ($values as $value) {
            $output[$value['storeViewCode'] . '_' . $value['productId']] = [
                'productId' => $value['productId'],
                'storeViewCode' => $value['storeViewCode'],
                'attributeSetId' => (int)($entities[(int)$value['productId']]['attribute_set_id'] ?? 0),
                'hasOptions' => (bool)($entities[(int)$value['productId']]['has_options'] ?? false),
                'requiredOptions' => (bool)($entities[(int)$value['productId']]['required_options'] ?? false),
            ];
        }

        return $output;
    }
}
