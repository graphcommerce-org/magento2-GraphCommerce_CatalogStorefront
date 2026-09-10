<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductListing\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontProductListing\Model\Mode;
use GraphCommerce\CatalogStorefrontProductListing\Model\Read\ListingDocuments;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds listing items from documents instead of loading their EAV attributes.
 *
 * _loadEntities() is the line between "which products, in what order" and "now fetch their data".
 * Filtering, sorting, pagination and layered navigation all happen before it, so the result set
 * and its order are unchanged. Only the source of the field values differs.
 *
 * Leaving _itemsById empty is deliberate: it makes _loadAttributes() return immediately, which is
 * the saving. addItem() still fills _items, so getItemById() works.
 *
 * If any product has no document the whole page loads from the database instead. A plugin cannot
 * populate _itemsById, so merging row by row would leave the fallback rows without attributes. A
 * slow page is better than a half-populated one.
 */
class ListingHydration
{
    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly ProductDocumentsInterface $products,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly ListingDocuments $page,
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
        if (!$this->mode->documents($storeId)) {
            return $proceed($printQuery, $logQuery);
        }

        try {
            $rows = $this->selectRows($subject);
            if ($rows === []) {
                return $proceed($printQuery, $logQuery);
            }

            $store = $this->storeManager->getStore($storeId);
            [$documents] = $this->storage->listing((string)$store->getCode(), array_keys($rows), null);
            $models = $this->products->build($store, $documents);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'catalog-storefront listing fallback: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $proceed($printQuery, $logQuery);
        }

        $missing = array_diff(array_keys($rows), array_keys($models));
        if ($missing !== []) {
            // Log the ids: all of them points at the store code or the cluster, a few of them
            // points at the feeds being behind on those products.
            $this->logger->info(sprintf(
                'catalog-storefront: listing loaded from the database, %d of %d ids have no '
                . 'usable document (%s)',
                count($missing),
                count($rows),
                implode(', ', array_slice($missing, 0, 10))
            ));

            return $proceed($printQuery, $logQuery);
        }

        // What a card needs beyond its own document, its children above all, is fetched for the
        // whole page from here.
        $this->page->set((string)$store->getCode(), $documents);

        foreach ($rows as $id => $row) {
            $model = $models[$id];
            $model->addData($row);
            $this->applyCompositeTierPrice($model, $documents[$id]);
            $model->setHasDataChanges(false);
            $subject->addItem($model);
        }

        return $subject;
    }

    /**
     * State that a configurable has no tier prices of its own, so nothing loads them.
     *
     * Rendering a price calls MinimalTierPriceCalculator to decide whether to show "As low as",
     * and TierPrice::getStoredTierPrices() loads the attribute whenever the key is absent — one
     * query per configurable on the page, which scales with page size.
     *
     * Magento does not let a configurable have tier prices: ConfigurablePrice::modifyMeta() hides
     * and disables the Advanced Pricing button for that type, and the price feed omits composites
     * for the same reason, which is why the document has no prices key to read this from.
     *
     * The guard is deliberately narrow. It applies only where the document says nothing about
     * prices, so if the feed ever starts exporting composite prices this stops firing on its own
     * and the real data is used instead. The residual risk is a tier price written by the API or
     * an import rather than the admin — impossible to create through the UI, but it would render
     * without its "As low as" line.
     *
     * @param Product $model
     * @param array $document
     * @return void
     */
    private function applyCompositeTierPrice(Product $model, array $document): void
    {
        if (isset($document['prices']) || ($document['type'] ?? null) !== Configurable::TYPE_CODE) {
            return;
        }

        $model->setData('tier_price', []);
    }

    /**
     * Run the collection's select as it was assembled and key the rows by entity id.
     *
     * The select is not reduced to entity_id. Its joins add the price index columns aliased as
     * minimal_price and max_price, which are not in the document and which
     * ConfigurableRegularPrice::isChildProductsOfEqualPrices() needs — without them it loads every
     * child of every configurable on the page. The aliases are also what an ORDER BY on price or
     * position refers to, so dropping the columns breaks sorting.
     *
     * @param Collection $subject
     * @return array<int, array>
     */
    private function selectRows(Collection $subject): array
    {
        $select = clone $subject->getSelect();

        $pageSize = $subject->getPageSize();
        if ($pageSize) {
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
