<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Writer;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;

/**
 * The product attributes feed lands as one attribute document per store
 * view, keyed by attribute code.
 */
class Attributes implements FeedWriterInterface
{
    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    public function write(array $rows): void
    {
        $upserts = [];
        $deletes = [];
        foreach ($rows as $row) {
            if (empty($row['attributeCode']) || empty($row['storeViewCode'])) {
                continue;
            }
            if (!empty($row['deleted'])) {
                $deletes[$row['storeViewCode']][] = $row['attributeCode'];
            } else {
                $upserts[$row['storeViewCode']][$row['attributeCode']] = $row;
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert('attribute', $store, $documents);
        }
        foreach ($deletes as $store => $codes) {
            $this->storage->delete('attribute', $store, $codes);
        }
    }
}
