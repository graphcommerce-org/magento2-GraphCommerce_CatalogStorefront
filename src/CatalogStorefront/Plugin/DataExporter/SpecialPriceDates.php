<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\DataExporter;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\ProductPriceDataExporter\Model\Provider\ProductPrice;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Leaves a special price out of the price feed on the days outside its from and
 * to dates, as core's price model does for the website's default store view.
 * The exporter hands the special price over without its dates.
 * `Cron\RefreshSpecialPriceWindows` exports a product again when its window
 * opens or closes.
 */
class SpecialPriceDates
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig,
        private readonly TimezoneInterface $timezone,
    ) {
    }

    public function afterGet(ProductPrice $subject, array $output): array
    {
        $productIds = [];
        foreach ($output as $row) {
            foreach ((array)($row['discounts'] ?? []) as $discount) {
                if (($discount['code'] ?? null) === 'special_price') {
                    $productIds[(int)$row['productId']] = true;
                }
            }
        }
        if (!$productIds) {
            return $output;
        }

        $codes = [];
        foreach (['special_from_date', 'special_to_date'] as $code) {
            $codes[(int)$this->eavConfig->getAttribute(Product::ENTITY, $code)->getId()] = $code;
        }
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['value' => $this->resourceConnection->getTableName('catalog_product_entity_datetime')], ['attribute_id', 'store_id', 'value'])
                ->join(
                    ['product' => $this->resourceConnection->getTableName('catalog_product_entity')],
                    'product.' . $linkField . ' = value.' . $linkField,
                    ['entity_id']
                )
                ->where('value.attribute_id IN (?)', array_keys($codes))
                ->where('product.entity_id IN (?)', array_keys($productIds))
        );
        // Per product and store view the date of each bound; a store view value wins over the default one.
        $dates = [];
        foreach ($rows as $row) {
            $dates[(int)$row['entity_id']][(int)$row['store_id']][$codes[(int)$row['attribute_id']]] = $row['value'];
        }

        foreach ($output as $key => $row) {
            $productDates = $dates[(int)$row['productId']] ?? null;
            if ($productDates === null || empty($row['discounts'])) {
                continue;
            }
            $store = $this->storeManager->getWebsite((int)$row['website_id'])->getDefaultStore();
            $bounds = ($productDates[(int)$store->getId()] ?? []) + ($productDates[0] ?? []);
            if ($this->timezone->isScopeDateInInterval($store, $bounds['special_from_date'] ?? null, $bounds['special_to_date'] ?? null)) {
                continue;
            }
            $output[$key]['discounts'] = array_values(array_filter(
                (array)$row['discounts'],
                static fn(array $discount) => ($discount['code'] ?? null) !== 'special_price'
            ));
        }

        return $output;
    }
}
