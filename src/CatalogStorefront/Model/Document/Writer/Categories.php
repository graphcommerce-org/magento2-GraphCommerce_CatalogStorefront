<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Writer;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;

/**
 * The categories feed lands as one category document per store view. The feed
 * sets isActive and includeInMenu to false below an inactive or hidden
 * ancestor; core reads the category's own value, so the document takes both
 * from the category's raw attribute values.
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
                $own = array_column((array)($row['customAttributes'] ?? []), 'value', 'attributeCode');
                foreach (['isActive' => 'is_active', 'includeInMenu' => 'include_in_menu'] as $field => $code) {
                    if (isset($own[$code])) {
                        $row[$field] = (int)$own[$code];
                    }
                }
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
