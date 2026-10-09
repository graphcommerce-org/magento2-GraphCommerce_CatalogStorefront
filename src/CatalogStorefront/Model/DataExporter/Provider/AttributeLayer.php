<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

/**
 * The attribute columns the exporter's own metadata record leaves out or rewrites: the layer
 * settings, what the attributes list of GraphQL reports beyond the exporter's flags, and the
 * visibility and backend type as stored, since the exporter lifts its own pseudo attributes.
 * `isVisible` is `is_visible`, which core's attribute lists filter on; the exporter's own
 * `visible` is the storefront visibility.
 */
class AttributeLayer
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
        $rows = $connection->fetchAssoc(
            $connection->select()
                ->from(['cea' => $this->resourceConnection->getTableName('catalog_eav_attribute')], [
                    'attribute_id', 'position', 'is_filterable', 'is_html_allowed_on_front', 'is_wysiwyg_enabled',
                    'is_used_for_promo_rules', 'is_visible_in_advanced_search', 'apply_to', 'additional_data', 'is_visible',
                ])
                ->join(['eav' => $this->resourceConnection->getTableName('eav_attribute')], 'eav.attribute_id = cea.attribute_id', ['frontend_class', 'default_value', 'backend_type'])
                ->where('cea.attribute_id IN (?)', $ids)
        );
        $output = [];
        foreach ($values as $value) {
            $id = (int)$value['id'];
            $row = $rows[$id] ?? [];
            $output[$value['storeViewCode'] . '_' . $id] = [
                'id' => (string)$id,
                'storeViewCode' => $value['storeViewCode'],
                'attributeId' => $id,
                'position' => (int)($row['position'] ?? 0),
                'filterableMode' => (int)($row['is_filterable'] ?? 0),
                'frontendClass' => $row['frontend_class'] ?? null,
                'defaultValue' => $row['default_value'] ?? null,
                'htmlAllowedOnFront' => !empty($row['is_html_allowed_on_front']),
                'wysiwygEnabled' => !empty($row['is_wysiwyg_enabled']),
                'usedForPromoRules' => !empty($row['is_used_for_promo_rules']),
                'visibleInAdvancedSearch' => !empty($row['is_visible_in_advanced_search']),
                'applyTo' => $row['apply_to'] ?? null,
                'additionalData' => $row['additional_data'] ?? null,
                'isVisible' => !empty($row['is_visible']),
                'backendType' => $row['backend_type'] ?? null,
            ];
        }

        return $output;
    }
}
