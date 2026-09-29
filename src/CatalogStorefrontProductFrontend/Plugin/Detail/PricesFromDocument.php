<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Customer\Model\Session;
use Magento\Store\Api\Data\StoreInterface;

class PricesFromDocument
{
    public function __construct(private readonly ProductPrice $price, private readonly Session $session)
    {
    }

    public function afterBuild(ProductDocumentsInterface $subject, array $models, StoreInterface $store, array $documents): array
    {
        $group = $this->price->groupKey((int)$this->session->getCustomerGroupId());
        foreach ($models as $id => $model) {
            $document = $documents[$id];
            $row = $this->price->row((array)($document['prices'] ?? []), $group);
            if ($row === null && !in_array($document['type'] ?? '', ['configurable', 'grouped'], true)) {
                throw new DocumentReadException('Catalog product requires price data: ' . $id);
            }
            $rule = null;
            foreach ((array)($row['discounts'] ?? []) as $discount) {
                if (($discount['code'] ?? '') === 'catalog_rule' && isset($discount['price'])) {
                    $rule = $rule === null ? (float)$discount['price'] : min($rule, (float)$discount['price']);
                }
            }
            $tiers = [];
            foreach ((array)($row['tierPrices'] ?? []) as $tier) {
                $percent = isset($tier['percentage']) ? (float)$tier['percentage'] : null;
                $value = in_array($document['type'] ?? '', ['bundle', 'bundle_fixed'], true)
                    ? ($percent ?? (float)($tier['price'] ?? 0))
                    : ($percent === null ? (float)($tier['price'] ?? 0) : (float)$row['regular'] * (1 - $percent / 100));
                $tiers[] = [
                    'website_id' => (int)$store->getWebsiteId(),
                    'all_groups' => 1,
                    'cust_group' => 32000,
                    'price_qty' => (float)$tier['qty'],
                    'price' => $value,
                    'website_price' => $value,
                    'percentage_value' => $percent,
                ];
            }
            $model->setData('catalog_rule_price', $rule);
            $model->setData('tier_price', $tiers);
            if ($row !== null && !in_array($document['type'] ?? '', ['configurable', 'grouped', 'bundle', 'bundle_fixed'], true)) {
                $model->setData('minimal_price', min($this->price->finalPrice($row), ...array_merge(
                    [(float)$row['regular']],
                    array_column($tiers, 'website_price')
                )));
            }
        }
        return $models;
    }
}
