<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Feed\Applier;

use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use GraphCommerce\CatalogStorefrontApi\Feed\FeedApplierInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Records the configurable relation on both sides: the parent document
 * keeps a variant map keyed by variant id, the variant document keeps the
 * list of its parent ids the price aggregation groups by. A link the feed
 * reports removed clears the map key and leaves the list. Both come
 * straight from the variants feed, so the read side needs no product load.
 * The list is merged with the one stored, so a batch that carries one
 * parent of a variant keeps its other parents.
 */
class Variants implements FeedApplierInterface
{
    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function apply(array $rows): void
    {
        $links = [];
        foreach ($rows as $row) {
            if (empty($row['parentId']) || empty($row['productId'])) {
                continue;
            }
            $links[(int)$row['productId']][(string)(int)$row['parentId']] = empty($row['deleted']);
        }
        if (!$links) {
            return;
        }
        foreach ($this->storeManager->getStores() as $storeModel) {
            $store = $storeModel->getCode();
            $stored = [];
            foreach ($this->storage->get($store, array_keys($links), ['parentIds']) as $entry) {
                $stored[(int)$entry->getId()] = (array)($entry->getData()['parentIds'] ?? []);
            }
            $upserts = [];
            foreach ($links as $variantId => $parents) {
                $parentIds = array_fill_keys($stored[$variantId] ?? [], true);
                foreach ($parents as $parentId => $linked) {
                    $upserts[(int)$parentId]['variantIds']['v' . $variantId] = $linked ? $variantId : null;
                    if ($linked) {
                        $parentIds[$parentId] = true;
                    } else {
                        unset($parentIds[$parentId]);
                    }
                }
                $upserts[$variantId]['parentIds'] = array_keys($parentIds);
            }
            $this->storage->upsert($store, $upserts);
        }
    }
}
