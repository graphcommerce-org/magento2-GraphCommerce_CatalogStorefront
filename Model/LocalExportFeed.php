<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use Magento\DataExporter\Model\ExportFeedInterface;
use Magento\DataExporter\Model\FeedExportStatus;
use Magento\DataExporter\Model\FeedExportStatusBuilder;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Local delivery for commerce-data-export feeds.
 *
 * Routes feed batches into the product document store. Each feed owns a slice
 * of the per-store-view product document:
 *   products             -> the base document (feed row as-is)
 *   prices               -> prices.<customerGroupCode>, fanned out per website
 *   inventoryStockStatus -> stock
 *   variants             -> variantIds on the configurable parent document
 *   reviews              -> reviews.r<reviewId> (vote percents where visible)
 * Other feeds are accepted and only persisted in their feed tables. A storage
 * failure reports status 500, so the feed machinery retries the batch by cron.
 */
class LocalExportFeed implements ExportFeedInterface
{
    private const STATUS_ACCEPTED = 200;
    private const STATUS_RETRY = 500;

    public function __construct(
        private readonly FeedExportStatusBuilder $feedExportStatusBuilder,
        private readonly ProductDocumentStorage $storage,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function export(array $data, FeedIndexMetadata $metadata): FeedExportStatus
    {
        try {
            match ($metadata->getFeedName()) {
                'products' => $this->applyProducts($data),
                'prices' => $this->applyPrices($data),
                'inventoryStockStatus' => $this->applyStock($data),
                'variants' => $this->applyVariants($data),
                'reviews' => $this->applyReviews($data),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('catalog-storefront: storing feed "%s" failed: %s', $metadata->getFeedName(), $e->getMessage())
            );

            return $this->feedExportStatusBuilder->build(self::STATUS_RETRY, $e->getMessage());
        }

        return $this->feedExportStatusBuilder->build(self::STATUS_ACCEPTED, 'Stored in document store');
    }

    private function applyProducts(array $rows): void
    {
        $upserts = [];
        $deletes = [];
        foreach ($rows as $row) {
            $store = $row['storeViewCode'];
            if (!empty($row['deleted'])) {
                $deletes[$store][] = (int)$row['productId'];
            } else {
                // The prices and stock keys belong to the price and inventory feed slices.
                unset($row['prices'], $row['stock']);
                $upserts[$store][(int)$row['productId']] = $row;
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert($store, $documents);
        }
        foreach ($deletes as $store => $ids) {
            $this->storage->delete($store, $ids);
        }
    }

    private function applyPrices(array $rows): void
    {
        $upserts = [];
        foreach ($rows as $row) {
            $group = 'g' . $row['customerGroupCode'];
            foreach ($this->storeViewCodesForWebsite($row['websiteCode']) as $store) {
                // A string prefix keeps the group map a JSON object; a bare "0" key
                // serializes as an array, and the doc merge replaces arrays wholesale.
                $upserts[$store][(int)$row['productId']]['prices'][$group] = $row;
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert($store, $documents);
        }
    }

    /**
     * Records each configurable variant on its parent document, keyed by variant
     * id. The parentId comes straight from the ProductVariantDataExporter feed,
     * so the read side can gather variant documents without a product load.
     */
    private function applyVariants(array $rows): void
    {
        $stores = array_map(static fn($store) => $store->getCode(), $this->storeManager->getStores());

        $upserts = [];
        foreach ($rows as $row) {
            if (empty($row['parentId']) || empty($row['productId'])) {
                continue;
            }
            $parentId = (int)$row['parentId'];
            $variantKey = 'v' . $row['productId'];
            foreach ($stores as $store) {
                $upserts[$store][$parentId]['variantIds'][$variantKey] = (int)$row['productId'];
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert($store, $documents);
        }
    }

    private function applyStock(array $rows): void
    {
        $upserts = [];
        $stores = array_map(
            static fn($store) => $store->getCode(),
            $this->storeManager->getStores()
        );
        foreach ($rows as $row) {
            foreach ($stores as $store) {
                $upserts[$store][(int)$row['productId']]['stock'] = $row;
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert($store, $documents);
        }
    }

    /**
     * Keeps one entry per review on the product document of every store view:
     * the vote percents (value / 5 * 100, as core counts them) where the review
     * is visible in that store view, null where it is not or once it is
     * deleted. The read side aggregates, so batches need not carry all reviews
     * of a product.
     */
    private function applyReviews(array $rows): void
    {
        $stores = array_map(static fn($store) => $store->getCode(), $this->storeManager->getStores());

        $upserts = [];
        foreach ($rows as $row) {
            if (empty($row['reviewId']) || empty($row['productId'])) {
                continue;
            }
            $percents = array_map(
                static fn(array $rating) => (float)$rating['value'] / 5 * 100,
                (array)($row['ratings'] ?? [])
            );
            foreach ($stores as $store) {
                $visible = empty($row['deleted']) && in_array($store, (array)($row['visibility'] ?? []), true);
                $upserts[$store][(int)$row['productId']]['reviews']['r' . $row['reviewId']] = $visible ? $percents : null;
            }
        }
        foreach ($upserts as $store => $documents) {
            $this->storage->upsert($store, $documents);
        }
    }

    /**
     * @return string[]
     */
    private function storeViewCodesForWebsite(string $websiteCode): array
    {
        $codes = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($store->getWebsite()->getCode() === $websiteCode) {
                $codes[] = $store->getCode();
            }
        }

        return $codes;
    }
}
