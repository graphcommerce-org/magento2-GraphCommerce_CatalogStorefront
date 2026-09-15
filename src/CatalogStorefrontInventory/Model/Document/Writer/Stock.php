<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;

/**
 * The inventory stock status feed is the stock slice of the product document.
 * The row names the websites its stock sells through, so it lands on the
 * documents of the store views of those websites, and only where the products
 * feed already wrote a document: a product outside the website has none. A
 * stock without a website leaves no trace; a website without a stock gets no
 * slice.
 */
class Stock implements FeedWriterInterface
{
    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly Scopes $scopes,
    ) {
    }

    public function write(array $rows): void
    {
        // A deleted row (the product left the stock's websites) names the product by sku only;
        // an empty slice reads as no slice, so the products feed's inStock answers again.
        $dropsByStore = [];
        $liveByStore = [];
        foreach ($rows as $row) {
            $stores = isset($row['websiteCodes'])
                ? array_merge([], ...array_map(
                    fn(string $websiteCode) => $this->scopes->storeViewsOfWebsite($websiteCode),
                    array_map('strval', (array)$row['websiteCodes'])
                ))
                : $this->scopes->storeViews();
            // The website codes route the row; the slice on the document is the stock itself.
            unset($row['websiteCodes']);
            foreach ($stores as $store) {
                if (empty($row['deleted'])) {
                    $liveByStore[$store][(int)$row['productId']] = $row;
                } else {
                    $dropsByStore[$store][] = (string)$row['sku'];
                }
            }
        }
        $upserts = [];
        foreach ($dropsByStore as $store => $skus) {
            foreach ($this->storage->storedBySku($store, array_values(array_unique($skus))) as $id => $document) {
                $upserts[$store][$id]['stock'] = [];
            }
        }
        foreach ($liveByStore as $store => $rowsById) {
            foreach ($this->storage->stored($store, array_keys($rowsById), ['sku']) as $id => $document) {
                $upserts[$store][$id]['stock'] = $rowsById[$id];
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert($store, $documents);
        }
    }
}
