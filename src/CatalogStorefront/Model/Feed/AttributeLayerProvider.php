<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed;

use Magento\Framework\App\ResourceConnection;

/**
 * Adds to an attribute metadata row its numeric id, the layered navigation
 * position and the filterable mode (0 off, 1 with results, 2 without) the
 * exporter reduces to a flag.
 */
class AttributeLayerProvider
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function get(array $values): array
    {
        $ids = array_unique(array_map(static fn(array $value) => (int)$value['id'], $values));
        if (!$ids) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $configuration = $connection->fetchAssoc(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_eav_attribute'), ['attribute_id', 'position', 'is_filterable'])
                ->where('attribute_id IN (?)', $ids)
        );
        $output = [];
        foreach ($values as $value) {
            $id = (int)$value['id'];
            $output[$value['storeViewCode'] . '_' . $id] = [
                'id' => (string)$id,
                'storeViewCode' => $value['storeViewCode'],
                'attributeId' => $id,
                'position' => (int)($configuration[$id]['position'] ?? 0),
                'filterableMode' => (int)($configuration[$id]['is_filterable'] ?? 0),
            ];
        }

        return $output;
    }
}
