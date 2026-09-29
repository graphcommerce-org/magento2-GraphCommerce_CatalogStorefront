<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection as SearchCollection;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds listing items from documents in the collection's selected order.
 * An empty _itemsById skips _loadAttributes(); addItem() fills _items.
 * Every selected product requires a usable document.
 */
class ListingHydration
{
    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly ProductDocumentsInterface $products,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly ListingDocuments $page,
        private readonly ProductPrice $productPrice,
        private readonly CustomerSession $customerSession,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Collection $subject
     * @param \Closure $proceed
     * @param bool $printQuery
     * @param bool $logQuery
     * @return Collection
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function around_loadEntities(
        Collection $subject,
        \Closure $proceed,
        $printQuery = false,
        $logQuery = false
    ) {
        if (!$subject->getFlag(CollectionFlag::FLAG)) {
            return $proceed($printQuery, $logQuery);
        }

        $storeId = (int)$subject->getStoreId();
        if (!$this->mode->listing($storeId)) {
            return $proceed($printQuery, $logQuery);
        }

        try {
            $rows = $this->selectRows($subject);
            if ($rows === []) {
                return $subject;
            }

            $store = $this->storeManager->getStore($storeId);
            $groupKey = $this->productPrice->groupKey((int)$this->customerSession->getCustomerGroupId());
            [$documents, $priceData] = $this->storage->listing((string)$store->getCode(), array_keys($rows), $groupKey);
            $models = $this->products->build($store, $documents);
        } catch (\Throwable $e) {
            $this->logger->error(
                'catalog-storefront listing document read: ' . $e->getMessage(),
                ['exception' => $e]
            );

            throw new DocumentReadException('Catalog listing documents could not be read.', 0, $e);
        }

        $missing = array_diff(array_keys($rows), array_keys($models));
        if ($missing !== []) {
            throw new DocumentReadException(sprintf(
                'Catalog listing requires usable documents for %d of %d products (%s).',
                count($missing),
                count($rows),
                implode(', ', array_slice($missing, 0, 10))
            ));
        }

        $this->page->add((string)$store->getCode(), $documents, $priceData);

        $position = 0;
        foreach ($rows as $id => $row) {
            if ($subject instanceof SearchCollection && $subject->getFlag('has_category_filter')) {
                $row['cat_index_position'] = $position++;
            }
            $model = $models[$id];
            $model->addData($row);
            $this->applyCompositeTierPrice($model, $documents[$id]);
            $model->setHasDataChanges(false);
            $subject->addItem($model);
        }
        if ($subject instanceof SearchCollection && $subject->getFlag('has_category_filter')) {
            $subject->setFlag('has_category_filter', false);
        }

        return $subject;
    }

    /**
     * Empty tier prices keep MinimalTierPriceCalculator from loading configurable attributes.
     */
    private function applyCompositeTierPrice(Product $model, array $document): void
    {
        if (isset($document['prices']) || ($document['type'] ?? null) !== Configurable::TYPE_CODE) {
            return;
        }

        $model->setData('tier_price', []);
    }

    /**
     * Preserves the core product order and price index columns.
     * Native fulltext search already selects the current page of product IDs.
     *
     * @return array<int, array>
     */
    private function selectRows(Collection $subject): array
    {
        $select = clone $subject->getSelect();

        $pageSize = $subject->getPageSize();
        if ($pageSize && !($subject instanceof SearchCollection)) {
            $select->limitPage((int)$subject->getCurPage(), (int)$pageSize);
        }

        $rows = [];
        foreach ($subject->getConnection()->fetchAll($select) as $row) {
            if (isset($row['entity_id'])) {
                $rows[(int)$row['entity_id']] = $row;
            }
        }

        return $rows;
    }
}
