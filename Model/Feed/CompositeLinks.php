<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed;

use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;

/**
 * Keeps the grouped and bundle links between documents, by product id, from
 * the products feed, which carries them by sku only: a child row lists its
 * parents, a parent row lists its children in its options. Both sides write
 * whatever the store can resolve at that moment, so the order in which the
 * feed delivers parent and child does not matter: a child row sets its parent
 * id lists to the parents that exist, a parent row adds itself to the children
 * that exist and removes itself from the children it no longer lists or, when
 * deleted, from all of them. The price read aggregates over `groupedParentIds`
 * and fetches the selections by `bundleParentIds`.
 */
class CompositeLinks
{
    private const TYPES = [
        'grouped' => ['parentIds' => 'groupedParentIds', 'childIds' => 'groupedChildIds'],
        'bundle' => ['parentIds' => 'bundleParentIds', 'childIds' => 'bundleChildIds'],
    ];

    public function __construct(
        private readonly ProductDocumentStorage $storage,
    ) {
    }

    /**
     * @param array[] $rows product rows of one store view keyed by product id, deleted rows excluded
     * @param int[] $deletedIds
     * @return array[] link keys per product id, to merge into the upserts of the same store view
     */
    public function upserts(string $storeViewCode, array $rows, array $deletedIds): array
    {
        // The feed folds a fixed bundle price type into the product type.
        $types = array_map(static fn(array $row) => $row['type'] === 'bundle_fixed' ? 'bundle' : ($row['type'] ?? ''), $rows);
        $childSkus = [];
        $skus = [];
        foreach ($rows as $id => $row) {
            foreach ((array)($row['parents'] ?? []) as $parent) {
                if (isset(self::TYPES[$parent['productType'] ?? ''], $parent['sku'])) {
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
            foreach ($this->storage->findBySku($storeViewCode, array_values(array_unique($skus))) as $entry) {
                $ids[$entry->getData()['sku']] ??= (int)$entry->getId();
            }
        }

        $upserts = [];
        foreach ($rows as $id => $row) {
            foreach (self::TYPES as $type => $keys) {
                $parentIds = [];
                foreach ((array)($row['parents'] ?? []) as $parent) {
                    if (($parent['productType'] ?? null) === $type && isset($ids[$parent['sku']])) {
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
            return $upserts;
        }
        $storedChildren = [];
        foreach ($this->storage->get($storeViewCode, array_keys($parents), array_column(self::TYPES, 'childIds')) as $entry) {
            $storedChildren[(int)$entry->getId()] = $entry->getData();
        }
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
        $storedParents = [];
        $unknown = array_diff(array_keys($changes), array_keys($rows));
        if ($unknown) {
            foreach ($this->storage->get($storeViewCode, $unknown, array_column(self::TYPES, 'parentIds')) as $entry) {
                $storedParents[(int)$entry->getId()] = $entry->getData();
            }
        }
        foreach ($changes as $childId => $byKey) {
            foreach ($byKey as $key => $linked) {
                $list = array_fill_keys(
                    (array)($upserts[$childId][$key] ?? $storedParents[$childId][$key] ?? []),
                    true
                );
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

        return $upserts;
    }
}
