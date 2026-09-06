<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document;

use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;

/**
 * Keeps the grouped and bundle links between documents, by product id, from
 * the products feed, which carries them by sku only: a child row lists its
 * parents, a parent row lists its children in its options. Both sides write
 * whatever the store can resolve at that moment, so the order in which the
 * feed delivers parent and child does not matter: a child row sets its parent
 * id lists to the parents that exist, a parent row adds itself to the children
 * that exist and removes itself from the children it no longer lists or, when
 * deleted, from all of them. A child outside the batch gets its parent id
 * lists changed in the store itself, so a parallel batch that touches the
 * same child cannot lose the change. The price read aggregates over
 * `groupedParentIds` and fetches the selections by `bundleParentIds`.
 */
class CompositeLinks
{
    private const TYPES = [
        'grouped' => ['parentIds' => 'groupedParentIds', 'childIds' => 'groupedChildIds'],
        'bundle' => ['parentIds' => 'bundleParentIds', 'childIds' => 'bundleChildIds'],
    ];

    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
    ) {
    }

    /**
     * @param array[] $rows product rows of one store view keyed by product id, deleted rows excluded
     * @param int[] $deletedIds
     * @return array{0: array[], 1: array[]} link keys per product id, to merge into the upserts of
     *   the same store view, and the list changes (add and remove per list key) of the children
     *   outside the batch
     */
    public function upserts(string $storeViewCode, array $rows, array $deletedIds): array
    {
        // The feed folds a fixed bundle price type into the product type, on a row and on a child's parents.
        $normalize = static fn(?string $type): string => $type === 'bundle_fixed' ? 'bundle' : (string)$type;
        $types = array_map(static fn(array $row) => $normalize($row['type'] ?? null), $rows);
        $childSkus = [];
        $skus = [];
        foreach ($rows as $id => $row) {
            foreach ((array)($row['parents'] ?? []) as $parent) {
                if (isset(self::TYPES[$normalize($parent['productType'] ?? null)], $parent['sku'])) {
                    $skus[] = $parent['sku'];
                }
            }
            if (isset(self::TYPES[$types[$id]])) {
                foreach ((array)($row['optionsV2'] ?? []) as $option) {
                    if (($option['type'] ?? null) === $types[$id]) {
                        $childSkus[$id] = array_merge($childSkus[$id] ?? [], array_column((array)($option['values'] ?? []), 'sku'));
                    }
                }
                $skus = array_merge($skus, $childSkus[$id] ?? []);
            }
        }
        $ids = [];
        foreach ($rows as $id => $row) {
            $ids[$row['sku']] = (int)$id;
        }
        if ($skus) {
            foreach ($this->storage->storedBySku($storeViewCode, array_values(array_unique($skus))) as $id => $document) {
                $ids[$document['sku']] ??= $id;
            }
        }

        $upserts = [];
        foreach ($rows as $id => $row) {
            foreach (self::TYPES as $linkType => $keys) {
                $parentIds = [];
                foreach ((array)($row['parents'] ?? []) as $parent) {
                    if ($normalize($parent["productType"] ?? null) === $linkType && isset($ids[$parent['sku']])) {
                        $parentIds[] = $ids[$parent['sku']];
                    }
                }
                $upserts[$id][$keys['parentIds']] = array_values(array_unique($parentIds));
            }
        }

        $parents = [];
        foreach ($childSkus as $id => $list) {
            $parents[$id] = [$types[$id], array_values(array_unique(array_filter(array_map(
                static fn(string $sku) => $ids[$sku] ?? null,
                $list
            ))))];
        }
        foreach ($deletedIds as $id) {
            $parents[$id] = [null, []];
        }
        if (!$parents) {
            return [$upserts, []];
        }
        $storedChildren = $this->storage->stored($storeViewCode, array_keys($parents), array_column(self::TYPES, 'childIds'));
        $changes = [];
        foreach ($parents as $parentId => [$type, $childIds]) {
            foreach (self::TYPES as $linkType => $keys) {
                $current = $linkType === $type ? $childIds : [];
                foreach ($current as $childId) {
                    $changes[$childId][$keys['parentIds']][$parentId] = true;
                }
                foreach (array_diff((array)($storedChildren[$parentId][$keys['childIds']] ?? []), $current) as $childId) {
                    $changes[(int)$childId][$keys['parentIds']][$parentId] = false;
                }
                if ($type !== null) {
                    $upserts[$parentId][$keys['childIds']] = $current;
                }
            }
        }
        $listChanges = [];
        foreach ($changes as $childId => $byKey) {
            foreach ($byKey as $key => $linked) {
                if (!isset($rows[$childId])) {
                    foreach ($linked as $parentId => $isLinked) {
                        $listChanges[$childId][$key][$isLinked ? 'add' : 'remove'][] = (int)$parentId;
                    }
                    continue;
                }
                $list = array_fill_keys((array)($upserts[$childId][$key] ?? []), true);
                foreach ($linked as $parentId => $isLinked) {
                    if ($isLinked) {
                        $list[$parentId] = true;
                    } else {
                        unset($list[$parentId]);
                    }
                }
                $upserts[$childId][$key] = array_map('intval', array_keys($list));
            }
        }

        return [$upserts, $listChanges];
    }
}
