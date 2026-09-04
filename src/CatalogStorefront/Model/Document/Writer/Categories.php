<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Writer;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;

/**
 * The categories feed lands as one category document per store view.
 */
class Categories implements FeedWriterInterface
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
            if (empty($row['categoryId']) || empty($row['storeViewCode'])) {
                continue;
            }
            if (!empty($row['deleted'])) {
                $deletes[$row['storeViewCode']][] = (int)$row['categoryId'];
            } else {
                $upserts[$row['storeViewCode']][(int)$row['categoryId']] = $row;
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert('category', $store, $documents);
        }
        foreach ($deletes as $store => $ids) {
            $this->storage->delete('category', $store, $ids);
        }
    }
}
