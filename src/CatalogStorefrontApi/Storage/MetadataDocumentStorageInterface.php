<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Storage;

/**
 * The documents of the feeds next to the products, one store per entity and
 * store view (categories by id, attributes by code, ratings by id, reviews by
 * id), keyed as the feed keys them. They are read by id, all at once, or by
 * a filtered and sorted query over the fields the entity declares in
 * `EntityMappings`. A storage module implements it for one search engine.
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
     * Removes the entity's documents of the store view as a whole; the next write starts a fresh store.
     */
    public function drop(string $entity, string $storeViewCode): void;

    public function count(string $entity, string $storeViewCode): int;

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

    /**
     * One page of the documents matching a filter, over declared fields.
     *
     * @param array<string, scalar|scalar[]> $filter field to the value or values it must have
     * @param array<array{0: string, 1: string}> $sort field and direction ('asc' or 'desc'), in order
     * @return array{documents: array<int|string, array>, total: int} the page keyed by id, and the match count
     */
    public function find(string $entity, string $storeViewCode, array $filter, array $sort, int $from, int $size): array;

    /**
     * The document count and the average of a declared field per group.
     *
     * @param scalar[] $groups the group values to answer for
     * @return array<string, array{count: int, avg: float|null}> keyed by group value; a group without documents is absent
     */
    public function stats(string $entity, string $storeViewCode, string $groupField, array $groups, string $valueField): array;
}
