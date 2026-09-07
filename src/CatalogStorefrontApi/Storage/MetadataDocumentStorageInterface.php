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
     * A fresh, empty index takes the writes of the entity in the store view;
     * the reads stay on the current documents until promote().
     */
    public function stage(string $entity, string $storeViewCode): void;

    /**
     * The staged documents serve the reads from now on; the ones they replace
     * are deleted.
     */
    public function promote(string $entity, string $storeViewCode): void;

    public function count(string $entity, string $storeViewCode): int;

    /**
     * @param array<int|string> $ids
     * @param string[] $fields the document keys to return; empty returns the whole document. Keys that
     *   are all declared fields come back as the index holds them: a scalar, or a list of values.
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
     * The documents matching one of the alternatives, each a filter as find() takes it, up to the result window.
     *
     * @param array<int, array<string, scalar|scalar[]>> $alternatives
     * @return array<int|string, array> keyed by id
     */
    public function any(string $entity, string $storeViewCode, array $alternatives): array;

    /**
     * Several reads in one request: a get() as `entity`, `ids` and `fields`, or an any() as
     * `entity` and `any`; the documents of each read come back in the same order.
     *
     * @param array<int, array{entity: string, ids?: array<int|string>, fields?: string[], any?: array}> $reads
     * @return array<int, array<int|string, array>>
     */
    public function batch(string $storeViewCode, array $reads): array;

    /**
     * The document count and the average of a declared field per group.
     *
     * @param scalar[] $groups the group values to answer for
     * @return array<string, array{count: int, avg: float|null}> keyed by group value; a group without documents is absent
     */
    public function stats(string $entity, string $storeViewCode, string $groupField, array $groups, string $valueField): array;
}
