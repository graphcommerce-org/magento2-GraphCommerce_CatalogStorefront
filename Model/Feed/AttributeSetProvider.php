<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed;

use Magento\Framework\App\ResourceConnection;

/**
 * Adds the attribute set id to a products feed row; the exporter carries the
 * set only by name.
 */
class AttributeSetProvider
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
        $sets = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id', 'attribute_set_id'])
                ->where('entity_id IN (?)', $ids)
        );
        $output = [];
        foreach ($values as $value) {
            $output[$value['storeViewCode'] . '_' . $value['productId']] = [
                'productId' => $value['productId'],
                'storeViewCode' => $value['storeViewCode'],
                'attributeSetId' => (int)($sets[(int)$value['productId']] ?? 0),
            ];
        }

        return $output;
    }
}
