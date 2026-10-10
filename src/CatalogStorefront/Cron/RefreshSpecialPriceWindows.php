<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Cron;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * Exports the prices of the products whose special price window opens or
 * closes around today again, so the price feed follows the dates as core's
 * price index does with its own special price refresh. The days around today
 * cover every store view time zone; a row whose price did not change keeps
 * its feed hash and is not written.
 */
class RefreshSpecialPriceWindows
{
    public const PRICE_FEED_INDEXER = 'catalog_data_exporter_product_prices';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig,
        private readonly IndexerRegistry $indexerRegistry,
    ) {
    }

    public function execute(): void
    {
        $attributeIds = array_map(
            fn(string $code) => (int)$this->eavConfig->getAttribute(Product::ENTITY, $code)->getId(),
            ['special_from_date', 'special_to_date']
        );
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $connection = $this->resourceConnection->getConnection();
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $productIds = $connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from(['value' => $this->resourceConnection->getTableName('catalog_product_entity_datetime')], [])
                ->join(
                    ['product' => $this->resourceConnection->getTableName('catalog_product_entity')],
                    'product.' . $linkField . ' = value.' . $linkField,
                    ['entity_id']
                )
                ->where('value.attribute_id IN (?)', $attributeIds)
                ->where('value.value >= ?', $today->modify('-2 days')->format('Y-m-d'))
                ->where('value.value < ?', $today->modify('+2 days')->format('Y-m-d'))
        );
        if ($productIds) {
            $this->indexerRegistry->get(self::PRICE_FEED_INDEXER)->reindexList(array_map('intval', $productIds));
        }
    }
}
