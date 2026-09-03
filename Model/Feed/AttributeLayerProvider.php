<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed;

use Magento\Framework\App\ResourceConnection;

/**
 * Feed provider of an attribute's layered navigation position and filterable
 * mode (1 with results, 2 without), which the facet builder orders and
 * filters by and the exporter's attribute metadata reduces to a flag.
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
                'position' => (int)($configuration[$id]['position'] ?? 0),
                'filterableMode' => (int)($configuration[$id]['is_filterable'] ?? 0),
            ];
        }

        return $output;
    }
}
