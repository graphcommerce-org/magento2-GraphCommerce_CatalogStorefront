<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\StoreAssignments;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Records the configurable relation on both sides: the parent document
 * keeps a variant map keyed by variant id, the variant document keeps the
 * list of its parent ids the price aggregation groups by. A link the feed
 * reports removed clears the map key and leaves the list. Both come
 * straight from the variants feed, so the read side needs no product load.
 * The list is merged with the one stored, so a batch that carries one
 * parent of a variant keeps its other parents. A side is written in the
 * store views its product is assigned to only.
 */
class Variants implements FeedWriterInterface
{
    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly StoreManagerInterface $storeManager,
        private readonly StoreAssignments $assignments,
    ) {
    }

    public function write(array $rows): void
    {
        $links = [];
        foreach ($rows as $row) {
            if (!empty($row['deleted']) && (empty($row['parentId']) || empty($row['productId']))) {
                throw new \RuntimeException(
                    'A deleted variants feed row has no productId or parentId; rebuild the product documents '
                    . 'so the variants feed retains deletion identities.'
                );
            }
            if (empty($row['parentId']) || empty($row['productId'])) {
                continue;
            }
            $links[(int)$row['productId']][(string)(int)$row['parentId']] = empty($row['deleted']);
        }
        if (!$links) {
            return;
        }
        $parentIdsAll = [];
        foreach ($links as $parents) {
            $parentIdsAll = array_merge($parentIdsAll, array_map('intval', array_keys($parents)));
        }
        $assigned = $this->assignments->storesOf(array_merge(array_keys($links), $parentIdsAll));
        foreach ($this->storeManager->getStores() as $storeModel) {
            $store = $storeModel->getCode();
            $inStore = static fn(int $id) => in_array($store, $assigned[$id] ?? [], true);
            $variantIds = array_filter(array_keys($links), $inStore);
            $stored = [];
            foreach ($this->storage->stored($store, $variantIds, ['parentIds']) as $id => $document) {
                $stored[$id] = (array)($document['parentIds'] ?? []);
            }
            $upserts = [];
            foreach ($links as $variantId => $parents) {
                $parentIds = array_fill_keys($stored[$variantId] ?? [], true);
                foreach ($parents as $parentId => $linked) {
                    if ($inStore((int)$parentId)) {
                        $upserts[(int)$parentId]['variantIds']['v' . $variantId] = $linked ? $variantId : null;
                    }
                    if ($linked) {
                        $parentIds[$parentId] = true;
                    } else {
                        unset($parentIds[$parentId]);
                    }
                }
                if ($inStore($variantId)) {
                    $upserts[$variantId]['parentIds'] = array_keys($parentIds);
                }
            }
            $this->storage->upsert($store, $upserts);
        }
    }
}
