<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Indexer;

use GraphCommerce\CatalogStorefront\Model\Storage\DocumentStore;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\EntityManager\HydratorPool;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Writes one flat product document per store view into the document store.
 *
 * A document is HydratorPool::extract() output of a repository-loaded product, so
 * it carries every EAV attribute plus loaded associations (media gallery, category
 * ids, links). The read side rehydrates a product model from it without queries.
 */
class ProductDocuments implements
    \Magento\Framework\Indexer\ActionInterface,
    \Magento\Framework\Mview\ActionInterface
{
    public const INDEXER_ID = 'graphcommerce_catalog_documents';

    public function __construct(
        private readonly DocumentStore $documentStore,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CollectionFactory $collectionFactory,
        private readonly HydratorPool $hydratorPool,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function executeFull(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $collection = $this->collectionFactory->create();
            $collection->addStoreFilter($store)
                ->addAttributeToFilter('status', Status::STATUS_ENABLED);
            $ids = array_map(intval(...), $collection->getAllIds());

            $storeId = (int)$store->getId();
            $this->documentStore->rebuild($storeId, (function () use ($ids, $storeId) {
                foreach ($ids as $id) {
                    try {
                        $product = $this->productRepository->getById($id, false, $storeId, true);
                    } catch (NoSuchEntityException) {
                        continue;
                    }
                    yield $id => $this->extract($product);
                }
            })());
        }
    }

    /**
     * @param int[] $ids
     */
    public function execute($ids): void
    {
        $ids = array_unique(array_map(intval(...), $ids));
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            if (!$this->documentStore->hasIndex($storeId)) {
                continue;
            }

            $documents = [];
            $deletes = [];
            foreach ($ids as $id) {
                try {
                    $product = $this->productRepository->getById($id, false, $storeId, true);
                } catch (NoSuchEntityException) {
                    $deletes[] = $id;
                    continue;
                }
                $inStore = in_array($store->getWebsiteId(), $product->getWebsiteIds());
                if (!$inStore || (int)$product->getStatus() !== Status::STATUS_ENABLED) {
                    $deletes[] = $id;
                    continue;
                }
                $documents[$id] = $this->extract($product);
            }
            $this->documentStore->upsert($storeId, $documents, $deletes);
        }
    }

    public function executeList(array $ids): void
    {
        $this->execute($ids);
    }

    public function executeRow($id): void
    {
        $this->execute([$id]);
    }

    private function extract(ProductInterface $product): array
    {
        return $this->hydratorPool->getHydrator(ProductInterface::class)->extract($product);
    }
}
