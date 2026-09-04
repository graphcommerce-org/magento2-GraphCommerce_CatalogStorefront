<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Store\Api\Data\StoreInterface;

class ProductDocuments implements ProductDocumentsInterface
{
    private const COMPOSITE_TYPES = ['configurable', 'grouped', 'bundle', 'bundle_fixed'];

    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly ProductModelBuilder $modelBuilder,
    ) {
    }

    public function documents(string $storeViewCode, array $ids): array
    {
        return $this->storage->get($storeViewCode, array_values(array_unique($ids)));
    }

    public function build(StoreInterface $store, array $documents): array
    {
        $storeId = (int)$store->getId();
        $models = [];
        foreach ($documents as $id => $document) {
            $model = $this->modelBuilder->build($document, $storeId);
            if ($model !== null) {
                $models[(int)$id] = $model;
            }
        }

        return $models;
    }

    public function priceDataLoader(StoreInterface $store, string $groupKey, array $documents): \Closure
    {
        return function () use ($store, $documents, $groupKey): array {
            $compositeIds = array_keys(array_filter(
                $documents,
                static fn(array $document) => in_array($document['type'] ?? '', self::COMPOSITE_TYPES, true)
            ));

            return $compositeIds ? $this->storage->priceData($store->getCode(), $compositeIds, $groupKey) : [];
        };
    }
}
