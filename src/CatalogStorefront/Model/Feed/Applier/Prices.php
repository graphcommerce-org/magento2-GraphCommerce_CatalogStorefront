<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed\Applier;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use GraphCommerce\CatalogStorefrontApi\Feed\FeedApplierInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Model\Group;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The prices slice keeps the feed rows by group key, fanned out to the store
 * views of the row's website. Next to it, priceIndex carries the regular and
 * final price per customer group with the fallback row already resolved, as
 * mapped floats the composite price aggregations read. A batch holds a
 * product's rows one group at a time, so the index is recomputed over the
 * rows already stored plus the batch.
 */
class Prices implements FeedApplierInterface
{
    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly StoreManagerInterface $storeManager,
        private readonly GroupManagementInterface $groupManagement,
        private readonly ProductPrice $productPrice,
    ) {
    }

    public function apply(array $rows): void
    {
        $storesByWebsite = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storesByWebsite[$store->getWebsite()->getCode()][] = $store->getCode();
        }
        $rowsByStore = [];
        foreach ($rows as $row) {
            foreach ($storesByWebsite[$row['websiteCode']] ?? [] as $store) {
                // A string prefix keeps the group map a JSON object; a bare "0" key
                // serializes as an array, and the doc merge replaces arrays wholesale.
                $rowsByStore[$store][(int)$row['productId']]['g' . $row['customerGroupCode']] = $row;
            }
        }
        $groupKeys = array_unique(array_merge(
            [$this->productPrice->groupKey(Group::NOT_LOGGED_IN_ID)],
            array_map(fn($group) => $this->productPrice->groupKey((int)$group->getId()), $this->groupManagement->getLoggedInGroups())
        ));

        foreach ($rowsByStore as $store => $products) {
            $stored = [];
            foreach ($this->storage->get($store, array_keys($products), ['prices']) as $entry) {
                $stored[(int)$entry->getId()] = (array)($entry->getData()['prices'] ?? []);
            }
            $upserts = [];
            foreach ($products as $productId => $groupRows) {
                $prices = $groupRows + ($stored[$productId] ?? []);
                $index = [];
                foreach ($groupKeys as $groupKey) {
                    $row = $this->productPrice->row($prices, $groupKey);
                    if ($row !== null) {
                        $index[$groupKey] = ['regular' => (float)$row['regular'], 'final' => $this->productPrice->finalPrice($row)];
                    }
                }
                $upserts[$productId] = ['prices' => $groupRows, 'priceIndex' => $index];
            }
            $this->storage->upsert($store, $upserts);
        }
    }
}
