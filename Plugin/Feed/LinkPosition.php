<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Feed;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\LinkTypeProvider;
use Magento\CatalogDataExporter\Model\Provider\Product\Links;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Adds the link position to every product link the feed exports and orders
 * the links by it, so the read side can list related, upsell and crosssell
 * products in the order the merchant set. Runs at index time.
 */
class LinkPosition
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool,
        private readonly LinkTypeProvider $linkTypeProvider,
    ) {
    }

    public function afterGet(Links $subject, array $output): array
    {
        $parentIds = array_unique(array_column($output, 'productId'));
        if (!$parentIds) {
            return $output;
        }
        $connection = $this->resourceConnection->getConnection();
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $select = $connection->select()
            ->from(['links' => $this->resourceConnection->getTableName('catalog_product_link')], ['link_type_id'])
            ->join(
                ['parent' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'parent.' . $linkField . ' = links.product_id',
                ['parent_id' => 'entity_id']
            )
            ->join(
                ['child' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'child.entity_id = links.linked_product_id',
                ['sku']
            )
            ->joinLeft(
                ['attribute' => $this->resourceConnection->getTableName('catalog_product_link_attribute')],
                "attribute.link_type_id = links.link_type_id AND attribute.product_link_attribute_code = 'position'",
                []
            )
            ->joinLeft(
                ['position' => $this->resourceConnection->getTableName('catalog_product_link_attribute_int')],
                'position.link_id = links.link_id'
                . ' AND position.product_link_attribute_id = attribute.product_link_attribute_id',
                ['position' => 'value']
            )
            ->where('parent.entity_id IN (?)', $parentIds);

        $typeCodes = array_flip($this->linkTypeProvider->getLinkTypes());
        $positions = [];
        foreach ($connection->fetchAll($select) as $row) {
            $type = $typeCodes[$row['link_type_id']] ?? null;
            $positions[$row['parent_id']][$type][$row['sku']] = (int)$row['position'];
        }

        foreach ($output as &$row) {
            $link = $row['links'];
            $row['links']['position'] = $positions[$row['productId']][$link['type']][$link['sku']] ?? 0;
        }
        unset($row);
        uasort($output, static fn(array $a, array $b) => [
            $a['storeViewCode'], (int)$a['productId'], $a['links']['type'], $a['links']['position'], $a['links']['sku'],
        ] <=> [
            $b['storeViewCode'], (int)$b['productId'], $b['links']['type'], $b['links']['position'], $b['links']['sku'],
        ]);

        return $output;
    }
}
