<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\CompositeLinks;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontApi\Document\ProductDocumentFieldInterface;

/**
 * The products feed is the base document per store view: the feed row as is,
 * run through the document fields (di.xml `fields`), with the composite links
 * kept by id. The prices and stock keys belong to the price and inventory
 * feed slices.
 */
class Products implements FeedWriterInterface
{
    /**
     * @param ProductDocumentFieldInterface[] $fields
     */
    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly CompositeLinks $compositeLinks,
        private readonly array $fields = [],
    ) {
    }

    public function write(array $rows): void
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
            foreach ($this->fields as $field) {
                $documents = $field->add($store, $documents);
            }
            [$links, $listChanges] = $this->compositeLinks->upserts($store, $documents, $deletes[$store] ?? []);
            foreach ($links as $id => $keys) {
                $documents[$id] = array_replace($documents[$id] ?? [], $keys);
            }
            $this->storage->upsert($store, $documents);
            $this->storage->updateLists($store, $listChanges);
            $this->storage->delete($store, $deletes[$store] ?? []);
        }
    }
}
