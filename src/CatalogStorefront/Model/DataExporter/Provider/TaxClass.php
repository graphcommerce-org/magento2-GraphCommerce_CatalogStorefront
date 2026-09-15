<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Exports the tax class as its id, the store view value over the default one,
 * for the products whose attribute set holds the attribute; the exporter's own
 * field carries the class by name. A read that leaves the custom attributes
 * slice out taxes the price with it. A product without a class gets no row, so
 * the field is null.
 */
class TaxClass
{
    private const ATTRIBUTE = 'tax_class_id';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig,
    ) {
    }

    public function get(array $values): array
    {
        $idsByStore = [];
        foreach ($values as $value) {
            $idsByStore[$value['storeViewCode']][(int)$value['productId']] = $value['productId'];
        }
        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $attributeId = (int)$this->eavConfig->getAttribute(Product::ENTITY, self::ATTRIBUTE)->getId();
        $output = [];
        foreach ($idsByStore as $storeViewCode => $productIds) {
            $storeId = (int)$this->storeManager->getStore($storeViewCode)->getId();
            $classes = [];
            $select = $connection->select()
                ->from(['e' => $this->resourceConnection->getTableName('catalog_product_entity')], ['entity_id'])
                ->join(
                    ['set_attribute' => $this->resourceConnection->getTableName('eav_entity_attribute')],
                    $connection->quoteInto(
                        'set_attribute.attribute_set_id = e.attribute_set_id AND set_attribute.attribute_id = ?',
                        $attributeId
                    ),
                    []
                )
                ->join(
                    ['value' => $this->resourceConnection->getTableName('catalog_product_entity_int')],
                    $connection->quoteInto(
                        'value.' . $linkField . ' = e.' . $linkField . ' AND value.attribute_id = ?',
                        $attributeId
                    ),
                    ['value']
                )
                ->where('e.entity_id IN (?)', array_keys($productIds))
                ->where('value.store_id IN (?)', [0, $storeId])
                ->order('value.store_id ASC');
            foreach ($connection->fetchAll($select) as $row) {
                $classes[(int)$row['entity_id']] = $row['value'];
            }
            foreach ($productIds as $id => $productId) {
                if (!isset($classes[$id])) {
                    continue;
                }
                $output[$storeViewCode . '_' . $id] = [
                    'productId' => $productId,
                    'storeViewCode' => $storeViewCode,
                    'taxClassId' => (int)$classes[$id],
                ];
            }
        }

        return $output;
    }
}
