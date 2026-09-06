<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Storage;

/**
 * The product documents, one per product and store view, assembled from feed
 * slices: a writer patches its own keys into the document, a reader fetches
 * whole documents by id or sku. A storage module implements it for one search
 * engine.
 */
interface ProductDocumentStorageInterface
{
    /**
     * Merges partial documents into the stored ones, creating what is missing.
     *
     * @param array<int, array> $documents partial document by product id
     */
    public function upsert(string $storeViewCode, array $documents): void;

    /**
     * @param int[] $ids
     */
    public function delete(string $storeViewCode, array $ids): void;

    /**
     * A fresh, empty index takes the writes of the store view; the reads stay
     * on the current documents until promote().
     */
    public function stage(string $storeViewCode): void;

    /**
     * The staged documents serve the reads from now on; the ones they replace
     * are deleted.
     */
    public function promote(string $storeViewCode): void;

    /**
     * Adds ids to and removes ids from id lists of stored documents in the
     * store itself, so parallel writers cannot lose each other's change; a
     * missing document is created with the added ids.
     *
     * @param array<int, array<string, array{add?: int[], remove?: int[]}>> $changes by product id and list key
     */
    public function updateLists(string $storeViewCode, array $changes): void;

    public function count(string $storeViewCode): int;

    /**
     * @param int[] $ids
     * @param string[] $fields the document keys to return; empty returns the whole document
     * @return array<int, array> the documents that exist, keyed by product id
     */
    public function get(string $storeViewCode, array $ids, array $fields = []): array;

    /**
     * @param string[] $skus
     * @return array<int, array> the documents that exist, keyed by product id
     */
    public function findBySku(string $storeViewCode, array $skus): array;

    /**
     * The documents as a writer sees them: read from the index that takes the
     * writes, which during a rebuild is the staged one. A writer that merges
     * into stored documents reads here, never through get().
     *
     * @return array<int, array> by product id
     */
    public function stored(string $storeViewCode, array $ids, array $fields = []): array;

    /**
     * @return array<int, array> by product id, the writer's view as stored()
     */
    public function storedBySku(string $storeViewCode, array $skus): array;

    /**
     * The documents of a listing page in one round trip and, when a group key
     * is given, the price data of the page's composite products with them.
     *
     * @param int[] $ids
     * @param string[] $sourceExcludes document keys to leave out
     * @return array{0: array<int, array>, 1: array} documents keyed by product id, price data as priceData() returns it
     */
    public function listing(string $storeViewCode, array $ids, array $sourceExcludes, ?string $groupKey): array;

    /**
     * Price data of composite products: the configurable and grouped price
     * ranges per parent id (`salable` and `all`, each [minimum regular,
     * minimum final, maximum regular, maximum final] or null), the bundle
     * selection documents per parent id and sku, and the bundles' option
     * slices per parent id.
     *
     * @param int[] $ids parent product ids
     * @return array{configurable: array, grouped: array, bundle: array, bundleOptions: array}
     */
    public function priceData(string $storeViewCode, array $ids, string $groupKey): array;
}
