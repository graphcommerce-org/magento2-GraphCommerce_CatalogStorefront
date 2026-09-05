<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Storage\Client\Config\Product as ProductEntity;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\QueryInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;

/**
 * Child documents for configurable parents.
 *
 * parentIds is one of the few indexed fields, so a single terms query returns every child of every
 * configurable on a listing page. Results are kept for the request, so only the first parent asked
 * for costs anything.
 */
class VariantRepository
{
    /** @var array<string, array<int, array<int, array>>> store view => parent id => child id => document */
    private array $cache = [];

    /**
     * @param ProductDocumentStorage $storage
     * @param QueryInterface $query
     */
    public function __construct(
        private readonly ProductDocumentStorage $storage,
        private readonly QueryInterface $query
    ) {
    }

    /**
     * @param string $storeViewCode
     * @param int[] $parentIds
     * @return array<int, array<int, array>> parent id => child id => document
     */
    public function forParents(string $storeViewCode, array $parentIds): array
    {
        $parentIds = array_map('intval', $parentIds);
        $known = array_keys($this->cache[$storeViewCode] ?? []);
        $missing = array_values(array_diff($parentIds, $known));

        if ($missing !== []) {
            $this->fetch($storeViewCode, $missing);
        }

        return array_intersect_key($this->cache[$storeViewCode] ?? [], array_flip($parentIds));
    }

    /**
     * @param string $storeViewCode
     * @param int $parentId
     * @return array<int, array> child id => document
     */
    public function forParent(string $storeViewCode, int $parentId): array
    {
        return $this->forParents($storeViewCode, [$parentId])[$parentId] ?? [];
    }

    /**
     * @param string $storeViewCode
     * @param int[] $parentIds
     * @return void
     */
    private function fetch(string $storeViewCode, array $parentIds): void
    {
        // Seed every parent asked for, so one with no children is not looked up again.
        foreach ($parentIds as $parentId) {
            $this->cache[$storeViewCode][$parentId] = [];
        }

        $entries = $this->query->searchFilteredEntries(
            $this->storage->aliasName($storeViewCode),
            ProductEntity::ENTITY_NAME,
            ['parentIds' => array_map('strval', $parentIds)]
        );

        foreach ($entries as $entry) {
            // Iterator keys are null throughout this storage layer; the id comes from the entry.
            $childId = (int)$entry->getId();
            $document = (array)$entry->getData();

            // A child can belong to more than one parent, and the query returns it once.
            foreach ((array)($document['parentIds'] ?? []) as $parentId) {
                if (isset($this->cache[$storeViewCode][(int)$parentId])) {
                    $this->cache[$storeViewCode][(int)$parentId][$childId] = $document;
                }
            }
        }

        // Ascending id, which is the order the child collection returns.
        foreach ($parentIds as $parentId) {
            ksort($this->cache[$storeViewCode][$parentId]);
        }
    }
}
