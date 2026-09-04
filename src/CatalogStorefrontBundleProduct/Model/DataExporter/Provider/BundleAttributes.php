<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProduct\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

/**
 * Feed provider of the bundle's sku type and shipment type: the BundleProduct
 * GraphQL fields derive from them and the exporter carries neither (the price
 * type is folded into the product type, the weight type and price view are
 * core fields).
 */
class BundleAttributes
{
    private const ATTRIBUTES = ['sku_type' => 'skuType', 'shipment_type' => 'shipmentType'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function get(array $values): array
    {
        $bundles = array_filter($values, static fn(array $value) => in_array($value['type'] ?? null, ['bundle', 'bundle_fixed'], true));
        if (!$bundles) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['value' => $this->resourceConnection->getTableName('catalog_product_entity_int')], ['entity_id', 'value'])
            ->join(
                ['attribute' => $this->resourceConnection->getTableName('eav_attribute')],
                'attribute.attribute_id = value.attribute_id',
                ['attribute_code']
            )
            ->where('value.entity_id IN (?)', array_unique(array_column($bundles, 'productId')))
            ->where('value.store_id = 0')
            ->where('attribute.attribute_code IN (?)', array_keys(self::ATTRIBUTES));
        $attributes = [];
        foreach ($connection->fetchAll($select) as $row) {
            $attributes[(int)$row['entity_id']][self::ATTRIBUTES[$row['attribute_code']]] = (string)$row['value'];
        }
        $output = [];
        foreach ($bundles as $value) {
            $output[$value['storeViewCode'] . '_' . $value['productId']] = [
                'productId' => $value['productId'],
                'storeViewCode' => $value['storeViewCode'],
            ] + ($attributes[(int)$value['productId']] ?? []);
        }

        return $output;
    }
}
