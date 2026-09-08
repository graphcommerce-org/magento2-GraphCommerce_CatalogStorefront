<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPrice\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Exports per product and store view the fixed product tax rows of the
 * store's website and of all websites, with the attribute's store label:
 * what core's weee resource reads per product and request, so the read side
 * picks the rows of the request's destination from the document.
 */
class FixedProductTaxes
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
            $idsByStore[$value['storeViewCode']][] = (int)$value['productId'];
        }
        $connection = $this->resourceConnection->getConnection();
        $output = [];
        foreach ($idsByStore as $storeViewCode => $ids) {
            $store = $this->storeManager->getStore($storeViewCode);
            $select = $connection->select()
                ->from(
                    ['weee' => $this->resourceConnection->getTableName('weee_tax')],
                    ['entity_id', 'country', 'state', 'website_id', 'value']
                )
                ->join(
                    ['attribute' => $this->resourceConnection->getTableName('eav_attribute')],
                    "attribute.attribute_id = weee.attribute_id AND attribute.frontend_input = 'weee'",
                    ['attribute_code', 'frontend_label']
                )
                ->joinLeft(
                    ['label' => $this->resourceConnection->getTableName('eav_attribute_label')],
                    $connection->quoteInto('label.attribute_id = attribute.attribute_id AND label.store_id = ?', (int)$store->getId()),
                    ['store_label' => 'value']
                )
                ->where('weee.entity_id IN (?)', array_unique($ids))
                ->where('weee.website_id IN (?)', [(int)$store->getWebsiteId(), 0]);
            foreach ($connection->fetchAll($select) as $row) {
                $key = implode('_', [$storeViewCode, $row['entity_id'], $row['attribute_code'], $row['country'], $row['state'], $row['website_id']]);
                $output[$key] = [
                    'productId' => (int)$row['entity_id'],
                    'storeViewCode' => $storeViewCode,
                    'fixedProductTaxes' => [
                        'attributeCode' => $row['attribute_code'],
                        'label' => $row['store_label'] ?: $row['frontend_label'],
                        'country' => $row['country'],
                        'region' => (int)$row['state'],
                        'website' => (int)$row['website_id'],
                        'value' => (float)$row['value'],
                    ],
                ];
            }
        }

        return $output;
    }
}
