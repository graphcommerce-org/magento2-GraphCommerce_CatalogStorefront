<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;

/**
 * Records the configurable relation on both sides: the parent document
 * keeps a variant map keyed by variant id, the variant document keeps the
 * list of its parent ids the price aggregation groups by. A link the feed
 * reports removed clears the map key and leaves the list. Both come
 * straight from the variants feed, so the read side needs no product load.
 * The list is merged with the one stored, so a batch that carries one
 * parent of a variant keeps its other parents. A side is written in the
 * store views that hold its product document, which the products feed writes
 * for the store views of the product's websites.
 */
class Variants implements FeedWriterInterface
{
    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly Scopes $scopes,
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
        $ids = array_values(array_unique(array_merge(array_keys($links), $parentIdsAll)));
        foreach ($this->scopes->storeViews() as $store) {
            $stored = $this->storage->stored($store, $ids, ['parentIds']);
            $inStore = static fn(int $id) => isset($stored[$id]);
            $upserts = [];
            foreach ($links as $variantId => $parents) {
                $parentIds = array_fill_keys((array)($stored[$variantId]['parentIds'] ?? []), true);
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
