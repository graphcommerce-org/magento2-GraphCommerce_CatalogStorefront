<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed\Applier;

use GraphCommerce\CatalogStorefront\Model\Storage\MetadataDocumentStorage;
use GraphCommerce\CatalogStorefrontApi\Feed\FeedApplierInterface;

/**
 * The product attributes feed lands as one attribute document per store
 * view, keyed by attribute code.
 */
class Attributes implements FeedApplierInterface
{
    public function __construct(
        private readonly MetadataDocumentStorage $attributeStorage,
    ) {
    }

    public function apply(array $rows): void
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
            $this->attributeStorage->upsert($store, $documents);
        }
        foreach ($deletes as $store => $codes) {
            $this->attributeStorage->delete($store, $codes);
        }
    }
}
