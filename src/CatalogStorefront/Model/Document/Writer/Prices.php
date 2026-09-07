<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Model\Group;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The prices slice keeps the feed rows by customer group id, fanned out to
 * the store views of the row's website. Next to it, priceIndex carries one
 * entry per customer group with the regular and final price and the fallback
 * row already resolved, the nested list the composite price aggregations
 * read. The feed names a group by the hash of its id and a batch holds a
 * product's rows one group at a time, so both are recomputed over the rows
 * already stored plus the batch; a stored row of a group that no longer
 * exists is dropped. A deleted row (a group price that stopped applying, a
 * catalog rule that no longer matches) names the product by sku only and
 * removes that group's stored row. A group created or deleted after a row
 * was exported is reflected when the row exports again.
 */
class Prices implements FeedWriterInterface
{
    /** The feed's customer group code of the row that applies to every group. */
    private const FALLBACK_CODE = '0';

    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly StoreManagerInterface $storeManager,
        private readonly GroupManagementInterface $groupManagement,
        private readonly ProductPrice $productPrice,
    ) {
    }

    public function write(array $rows): void
    {
        $storesByWebsite = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storesByWebsite[$store->getWebsite()->getCode()][] = $store->getCode();
        }
        $groupIds = array_unique(array_merge(
            [Group::NOT_LOGGED_IN_ID],
            array_map(static fn($group) => (int)$group->getId(), $this->groupManagement->getLoggedInGroups())
        ));
        $groupByCode = [self::FALLBACK_CODE => ProductPrice::FALLBACK_GROUP];
        foreach ($groupIds as $groupId) {
            $groupByCode[sha1((string)$groupId)] = $this->productPrice->groupKey($groupId);
        }
        $rowsByStore = [];
        $dropsByStore = [];
        foreach ($rows as $row) {
            $group = $groupByCode[$row['customerGroupCode'] ?? ''] ?? null;
            if ($group === null) {
                continue;
            }
            unset($row['customerGroupCode']);
            foreach ($storesByWebsite[$row['websiteCode']] ?? [] as $store) {
                if (!empty($row['deleted'])) {
                    $dropsByStore[$store][(string)$row['sku']][] = $group;
                } else {
                    $rowsByStore[$store][(int)$row['productId']][$group] = ['group' => $group] + $row;
                }
            }
        }

        foreach (array_unique(array_merge(array_keys($rowsByStore), array_keys($dropsByStore))) as $store) {
            $products = $rowsByStore[$store] ?? [];
            $drops = [];
            if (!empty($dropsByStore[$store])) {
                foreach ($this->storage->storedBySku($store, array_keys($dropsByStore[$store])) as $id => $document) {
                    $drops[$id] = $dropsByStore[$store][(string)$document['sku']];
                    $products[$id] ??= [];
                }
            }
            $stored = [];
            foreach ($this->storage->stored($store, array_keys($products), ['prices']) as $id => $document) {
                foreach ((array)($document['prices'] ?? []) as $row) {
                    if (in_array((string)$row['group'], $groupByCode, true) && !in_array((string)$row['group'], $drops[$id] ?? [], true)) {
                        $stored[$id][(string)$row['group']] = $row;
                    }
                }
            }
            $upserts = [];
            foreach ($products as $productId => $groupRows) {
                $prices = array_values($groupRows + ($stored[$productId] ?? []));
                $index = [];
                foreach ($groupIds as $groupId) {
                    $groupKey = $this->productPrice->groupKey($groupId);
                    $row = $this->productPrice->row($prices, $groupKey);
                    if ($row !== null) {
                        $index[] = [
                            'group' => $groupKey,
                            'regular' => (float)$row['regular'],
                            'final' => $this->productPrice->finalPrice($row),
                            'precision' => $this->productPrice->finalPrecision($row),
                        ];
                    }
                }
                $upserts[$productId] = ['prices' => $prices, 'priceIndex' => $index];
            }
            $this->storage->upsert($store, $upserts);
        }
    }
}
