<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed\Applier;

use GraphCommerce\CatalogStorefront\Model\Feed\CompositeLinks;
use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use GraphCommerce\CatalogStorefrontApi\Feed\FeedApplierInterface;
use GraphCommerce\CatalogStorefrontApi\Feed\ProductDocumentEnricherInterface;

/**
 * The products feed is the base document per store view: the feed row as is,
 * run through the enrichers (di.xml `enrichers`), with the composite links
 * kept by id. The prices and stock keys belong to the price and inventory
 * feed slices.
 */
class Products implements FeedApplierInterface
{
    /**
     * @param ProductDocumentEnricherInterface[] $enrichers
     */
    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly CompositeLinks $compositeLinks,
        private readonly array $enrichers = [],
    ) {
    }

    public function apply(array $rows): void
    {
        $upserts = [];
        $deletes = [];
        foreach ($rows as $row) {
            $store = $row['storeViewCode'];
            if (!empty($row['deleted'])) {
                $deletes[$store][] = (int)$row['productId'];
            } else {
                unset($row['prices'], $row['stock']);
                $upserts[$store][(int)$row['productId']] = $row;
            }
        }
        foreach (array_unique(array_merge(array_keys($upserts), array_keys($deletes))) as $store) {
            $documents = $upserts[$store] ?? [];
            foreach ($this->enrichers as $enricher) {
                $documents = $enricher->enrich($store, $documents);
            }
            foreach ($this->compositeLinks->upserts($store, $documents, $deletes[$store] ?? []) as $id => $links) {
                $documents[$id] = array_replace($documents[$id] ?? [], $links);
            }
            $this->storage->upsert($store, $documents);
            $this->storage->delete($store, $deletes[$store] ?? []);
        }
    }
}
