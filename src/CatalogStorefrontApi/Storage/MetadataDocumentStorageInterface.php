<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Storage;

/**
 * The documents of the metadata feeds per store view, one store per entity
 * (categories by id, attributes by code, ratings by id), keyed as the feed
 * keys them. They are read by id or all at once. A storage module implements
 * it for one search engine.
 */
interface MetadataDocumentStorageInterface
{
    /**
     * @param array<int|string, array> $documents by document id
     */
    public function upsert(string $entity, string $storeViewCode, array $documents): void;

    /**
     * @param array<int|string> $ids
     */
    public function delete(string $entity, string $storeViewCode, array $ids): void;

    /**
     * @param array<int|string> $ids
     * @param string[] $fields the document keys to return; empty returns the whole document
     * @return array<int|string, array> the documents that exist, keyed by id
     */
    public function get(string $entity, string $storeViewCode, array $ids, array $fields = []): array;

    /**
     * @return array<int|string, array> every document of the store view, keyed by id
     */
    public function all(string $entity, string $storeViewCode): array;
}
