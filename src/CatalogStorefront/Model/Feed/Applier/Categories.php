<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed\Applier;

use GraphCommerce\CatalogStorefront\Model\Storage\MetadataDocumentStorage;
use GraphCommerce\CatalogStorefrontApi\Feed\FeedApplierInterface;

/**
 * The categories feed lands as one category document per store view.
 */
class Categories implements FeedApplierInterface
{
    public function __construct(
        private readonly MetadataDocumentStorage $categoryStorage,
    ) {
    }

    public function apply(array $rows): void
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
            $this->categoryStorage->upsert($store, $documents);
        }
        foreach ($deletes as $store => $ids) {
            $this->categoryStorage->delete($store, $ids);
        }
    }
}
