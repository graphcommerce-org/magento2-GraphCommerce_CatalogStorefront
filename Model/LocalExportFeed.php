<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use GraphCommerce\CatalogStorefront\Model\Feed\ConfigurableOptionsBuilder;
use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Model\Group;
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
 *   products             -> the base document (feed row as-is, plus the
 *                           configurable_options response shape)
 *   prices               -> prices.<customerGroupCode>, fanned out per website,
 *                           plus priceIndex.<group key> for the price aggregation
 *   inventoryStockStatus -> stock
 *   variants             -> variantIds on the parent, parentIds on the variant
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
        private readonly GroupManagementInterface $groupManagement,
        private readonly ProductPrice $productPrice,
        private readonly ConfigurableOptionsBuilder $configurableOptionsBuilder,
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
                if (($row['type'] ?? null) === 'configurable') {
                    $row['configurableOptions'] = $this->configurableOptionsBuilder->build($row);
                }
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

    /**
     * The prices slice keeps the feed rows by group key. Next to it, priceIndex
     * carries the regular and final price per customer group with the fallback
     * row already resolved, as mapped floats the configurable price
     * aggregation reads. A batch holds a product's rows one group at a time,
     * so the index is recomputed over the rows already stored plus the batch.
     */
    private function applyPrices(array $rows): void
    {
        $rowsByStore = [];
        foreach ($rows as $row) {
            foreach ($this->storeViewCodesForWebsite($row['websiteCode']) as $store) {
                // A string prefix keeps the group map a JSON object; a bare "0" key
                // serializes as an array, and the doc merge replaces arrays wholesale.
                $rowsByStore[$store][(int)$row['productId']]['g' . $row['customerGroupCode']] = $row;
            }
        }
        $groupKeys = array_unique(array_merge(
            [$this->productPrice->groupKey(Group::NOT_LOGGED_IN_ID)],
            array_map(fn($group) => $this->productPrice->groupKey((int)$group->getId()), $this->groupManagement->getLoggedInGroups())
        ));

        foreach ($rowsByStore as $store => $products) {
            $stored = [];
            foreach ($this->storage->get($store, array_keys($products), ['prices']) as $entry) {
                $stored[(int)$entry->getId()] = (array)($entry->getData()['prices'] ?? []);
            }
            $upserts = [];
            foreach ($products as $productId => $groupRows) {
                $prices = $groupRows + ($stored[$productId] ?? []);
                $index = [];
                foreach ($groupKeys as $groupKey) {
                    $row = $this->productPrice->row($prices, $groupKey);
                    if ($row !== null) {
                        $index[$groupKey] = ['regular' => (float)$row['regular'], 'final' => $this->productPrice->finalPrice($row)];
                    }
                }
                $upserts[$productId] = ['prices' => $groupRows, 'priceIndex' => $index];
            }
            $this->storage->upsert($store, $upserts);
        }
    }

    /**
     * Records the configurable relation on both sides: the parent document
     * keeps a variant map keyed by variant id, the variant document keeps the
     * list of its parent ids the price aggregation groups by. A link the feed
     * reports removed clears the map key and leaves the list. Both come
     * straight from the ProductVariantDataExporter feed, so the read side needs
     * no product load. The list is merged with the one stored, so a batch that
     * carries one parent of a variant keeps its other parents.
     */
    private function applyVariants(array $rows): void
    {
        $stores = array_map(static fn($store) => $store->getCode(), $this->storeManager->getStores());

        $links = [];
        foreach ($rows as $row) {
            if (empty($row['parentId']) || empty($row['productId'])) {
                continue;
            }
            $links[(int)$row['productId']][(string)(int)$row['parentId']] = empty($row['deleted']);
        }
        if (!$links) {
            return;
        }
        foreach ($stores as $store) {
            $stored = [];
            foreach ($this->storage->get($store, array_keys($links), ['parentIds']) as $entry) {
                $stored[(int)$entry->getId()] = (array)($entry->getData()['parentIds'] ?? []);
            }
            $upserts = [];
            foreach ($links as $variantId => $parents) {
                $parentIds = array_fill_keys($stored[$variantId] ?? [], true);
                foreach ($parents as $parentId => $linked) {
                    $upserts[(int)$parentId]['variantIds']['v' . $variantId] = $linked ? $variantId : null;
                    if ($linked) {
                        $parentIds[$parentId] = true;
                    } else {
                        unset($parentIds[$parentId]);
                    }
                }
                $upserts[$variantId]['parentIds'] = array_keys($parentIds);
            }
            $this->storage->upsert($store, $upserts);
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
