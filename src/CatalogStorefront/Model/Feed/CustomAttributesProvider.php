<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Exports per product and store view the raw value of every attribute of the
 * product's attribute set that has a value, the store view value over the
 * default one, as a full product load puts them on the model: the custom
 * attributes field lists these. The tier price backend adds its attribute the
 * way the product load does, so its value is the string core serializes.
 */
class CustomAttributesProvider
{
    private const BACKEND_TYPES = ['varchar', 'int', 'decimal', 'datetime', 'text'];

    private const TIER_PRICE = 'tier_price';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig,
        private readonly ProductFactory $productFactory,
    ) {
    }

    public function get(array $values): array
    {
        $typesByStore = [];
        foreach ($values as $value) {
            $typesByStore[$value['storeViewCode']][(int)$value['productId']] = $value['type'] ?? null;
        }
        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $tierPrice = $this->eavConfig->getAttribute(Product::ENTITY, self::TIER_PRICE);
        $output = [];
        foreach ($typesByStore as $storeViewCode => $types) {
            $storeId = (int)$this->storeManager->getStore($storeViewCode)->getId();
            $ids = array_keys($types);
            $rows = [];
            foreach (self::BACKEND_TYPES as $backendType) {
                $select = $connection->select()
                    ->from(['e' => $this->resourceConnection->getTableName('catalog_product_entity')], ['entity_id'])
                    ->join(
                        ['set_attribute' => $this->resourceConnection->getTableName('eav_entity_attribute')],
                        'set_attribute.attribute_set_id = e.attribute_set_id',
                        []
                    )
                    ->join(
                        ['attribute' => $this->resourceConnection->getTableName('eav_attribute')],
                        $connection->quoteInto(
                            'attribute.attribute_id = set_attribute.attribute_id AND attribute.backend_type = ?',
                            $backendType
                        ),
                        ['attribute_id', 'attribute_code']
                    )
                    ->join(
                        ['value' => $this->resourceConnection->getTableName('catalog_product_entity_' . $backendType)],
                        'value.' . $linkField . ' = e.' . $linkField . ' AND value.attribute_id = attribute.attribute_id',
                        ['value']
                    )
                    ->where('e.entity_id IN (?)', $ids)
                    ->where('value.store_id IN (?)', [0, $storeId])
                    ->order('value.store_id ASC');
                foreach ($connection->fetchAll($select) as $row) {
                    $rows[(int)$row['entity_id']][$row['attribute_code']] = $row['value'];
                }
            }

            // Core walks the backends of the set's attributes that apply to the product type after the load.
            $applyTo = $tierPrice->getApplyTo();
            $withTierPrice = $connection->fetchCol(
                $connection->select()
                    ->from(['e' => $this->resourceConnection->getTableName('catalog_product_entity')], ['entity_id'])
                    ->join(
                        ['set_attribute' => $this->resourceConnection->getTableName('eav_entity_attribute')],
                        $connection->quoteInto(
                            'set_attribute.attribute_set_id = e.attribute_set_id AND set_attribute.attribute_id = ?',
                            (int)$tierPrice->getId()
                        ),
                        []
                    )
                    ->where('e.entity_id IN (?)', $ids)
            );
            foreach ($withTierPrice as $id) {
                $typeId = ($types[(int)$id] ?? null) === 'bundle_fixed' ? 'bundle' : $types[(int)$id];
                if ($applyTo && !in_array($typeId, $applyTo, true)) {
                    continue;
                }
                $product = $this->productFactory->create();
                $product->setData([
                    'entity_id' => (int)$id,
                    $linkField => (int)$id,
                    'type_id' => $typeId,
                    'store_id' => $storeId,
                    'price' => $rows[(int)$id]['price'] ?? null,
                ]);
                $tierPrice->getBackend()->afterLoad($product);
                $rows[(int)$id][self::TIER_PRICE] = $product->getData(self::TIER_PRICE);
            }

            foreach ($rows as $id => $attributes) {
                foreach ($attributes as $code => $value) {
                    if (is_array($value)) {
                        $value = count($value) !== count($value, COUNT_RECURSIVE) ? json_encode($value) : implode(',', $value);
                    }
                    $output[$storeViewCode . '_' . $id . '_' . $code] = [
                        'productId' => $id,
                        'storeViewCode' => $storeViewCode,
                        'type' => $types[$id],
                        'customAttributes' => ['attributeCode' => $code, 'value' => (string)$value],
                    ];
                }
            }
        }

        return $output;
    }
}
