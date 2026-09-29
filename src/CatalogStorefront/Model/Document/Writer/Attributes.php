<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Writer;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The product attributes feed lands as one attribute document per store
 * view, keyed by attribute code.
 */
class Attributes implements FeedWriterInterface
{
    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
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
        foreach (array_unique(array_merge(array_keys($upserts), array_keys($deletes))) as $store) {
            $documents = $upserts[$store] ?? [];
            foreach (['category', 'search'] as $layer) {
                $collection = $this->collectionFactory->create();
                $collection->setItemObjectClass(Attribute::class)
                    ->addStoreLabel($this->storeManager->getStore($store)->getId())
                    ->setOrder('position', 'ASC');
                if ($layer === 'search') {
                    $collection->addIsFilterableInSearchFilter()->addVisibleFilter();
                } else {
                    $collection->addIsFilterableFilter();
                }
                foreach (array_values($collection->getItems()) as $order => $attribute) {
                    $documents[$attribute->getAttributeCode()][$layer . 'FilterOrder'] = $order;
                }
            }
            $this->storage->upsert('attribute', $store, $documents);
        }
        foreach ($deletes as $store => $codes) {
            $this->storage->delete('attribute', $store, $codes);
        }
    }
}
