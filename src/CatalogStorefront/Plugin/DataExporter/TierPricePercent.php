<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\DataExporter;

use Magento\Framework\App\ResourceConnection;
use Magento\ProductPriceDataExporter\Model\Provider\ProductPrice;

/**
 * Puts the percent of a percent tier price on the price feed's tier rows; the
 * exporter hands such a tier over as a zero price.
 */
class TierPricePercent
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function afterGet(ProductPrice $subject, array $output): array
    {
        $productIds = [];
        foreach ($output as $row) {
            if (!empty($row['tierPrices'])) {
                $productIds[] = (int)$row['productId'];
            }
        }
        if (!$productIds) {
            return $output;
        }
        $connection = $this->resourceConnection->getConnection();
        $percents = [];
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('catalog_product_entity_tier_price'), ['entity_id', 'qty', 'percentage_value'])
                ->where('entity_id IN (?)', array_unique($productIds))
                ->where('percentage_value IS NOT NULL')
        );
        foreach ($rows as $row) {
            $percents[(int)$row['entity_id']][(string)(float)$row['qty']] = (float)$row['percentage_value'];
        }
        foreach ($output as $key => $row) {
            foreach ((array)($row['tierPrices'] ?? []) as $index => $tier) {
                $percent = $percents[(int)$row['productId']][(string)(float)($tier['qty'] ?? 0)] ?? null;
                if ($percent !== null && empty($tier['price'])) {
                    $output[$key]['tierPrices'][$index]['percentage'] = $percent;
                }
            }
        }

        return $output;
    }
}
