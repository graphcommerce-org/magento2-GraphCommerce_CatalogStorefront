<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The attribute columns the exporter's own metadata record leaves out or rewrites: the layer
 * settings, what the attributes list of GraphQL reports beyond the exporter's flags, and the
 * visibility and backend type as stored, since the exporter lifts its own pseudo attributes.
 */
class AttributeLayer
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
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
        $orders = [];
        foreach (array_unique(array_column($values, 'storeViewCode')) as $code) {
            foreach (['category', 'search'] as $layer) {
                $collection = $this->collectionFactory->create();
                $collection->setItemObjectClass(Attribute::class)
                    ->addStoreLabel($this->storeManager->getStore($code)->getId())
                    ->setOrder('position', 'ASC');
                if ($layer === 'search') {
                    $collection->addIsFilterableInSearchFilter()->addVisibleFilter();
                } else {
                    $collection->addIsFilterableFilter();
                }
                $orders[$code][$layer] = array_flip(array_keys($collection->getItems()));
            }
        }
        $output = [];
        foreach ($values as $value) {
            $id = (int)$value['id'];
            $row = $rows[$id] ?? [];
            $output[$value['storeViewCode'] . '_' . $id] = [
                'id' => (string)$id,
                'storeViewCode' => $value['storeViewCode'],
                'attributeId' => $id,
                'position' => (int)($row['position'] ?? 0),
                'categoryFilterOrder' => $orders[$value['storeViewCode']]['category'][$id] ?? null,
                'searchFilterOrder' => $orders[$value['storeViewCode']]['search'][$id] ?? null,
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
