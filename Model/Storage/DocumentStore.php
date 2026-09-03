<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Product documents in the configured search engine, one index per store view.
 *
 * The index is a document store, not a search index: mapping is `dynamic: false`,
 * documents are fetched by _id only. Full rebuilds write a new versioned index and
 * swap the alias, so readers never see a partial index.
 */
class DocumentStore
{
    private const BULK_CHUNK = 500;

    private ?object $client = null;

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function aliasName(int $storeId): string
    {
        $engine = (string)$this->scopeConfig->getValue('catalog/search/engine');
        $prefix = (string)$this->scopeConfig->getValue('catalog/search/' . $engine . '_index_prefix');

        return $prefix . '_gc_products_' . $storeId;
    }

    public function hasIndex(int $storeId): bool
    {
        return $this->client()->existsAlias($this->aliasName($storeId));
    }

    /**
     * @param iterable<int, array> $documents entity_id => flat document
     */
    public function rebuild(int $storeId, iterable $documents): void
    {
        $alias = $this->aliasName($storeId);
        $index = $alias . '_v' . time();

        $this->client()->createIndex($index, [
            'settings' => ['number_of_shards' => 1],
            'mappings' => ['dynamic' => false, 'properties' => new \stdClass()],
        ]);

        $chunk = [];
        foreach ($documents as $entityId => $document) {
            $chunk[$entityId] = $document;
            if (count($chunk) >= self::BULK_CHUNK) {
                $this->bulkIndex($index, $chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            $this->bulkIndex($index, $chunk);
        }

        $old = $this->client()->existsAlias($alias)
            ? array_keys($this->client()->getAlias($alias))
            : [];
        $this->client()->updateAlias($alias, $index, $old[0] ?? '');
        foreach ($old as $oldIndex) {
            if ($oldIndex !== $index) {
                $this->client()->deleteIndex($oldIndex);
            }
        }
    }

    /**
     * Partial update into the live alias. A missing alias means no full build ran
     * yet; the caller can ignore that state because reads fall back to the database.
     *
     * @param array<int, array> $documents entity_id => flat document
     * @param int[] $deleteIds
     */
    public function upsert(int $storeId, array $documents, array $deleteIds = []): void
    {
        if (!$this->hasIndex($storeId)) {
            return;
        }
        $alias = $this->aliasName($storeId);
        if ($documents) {
            $this->bulkIndex($alias, $documents);
        }
        if ($deleteIds) {
            $body = [];
            foreach ($deleteIds as $id) {
                $body[] = ['delete' => ['_index' => $alias, '_id' => (string)$id]];
            }
            $this->client()->bulkQuery(['body' => $body, 'refresh' => true]);
        }
    }

    /**
     * @param int[] $entityIds
     * @return array<int, array> entity_id => document, misses omitted
     */
    public function get(int $storeId, array $entityIds): array
    {
        $result = $this->client()->query([
            'index' => $this->aliasName($storeId),
            'body' => [
                'query' => ['ids' => ['values' => array_map(strval(...), $entityIds)]],
                'size' => count($entityIds),
            ],
        ]);

        $documents = [];
        foreach ($result['hits']['hits'] ?? [] as $hit) {
            $documents[(int)$hit['_id']] = $hit['_source'];
        }

        return $documents;
    }

    private function bulkIndex(string $index, array $documents): void
    {
        $body = [];
        foreach ($documents as $entityId => $document) {
            $body[] = ['index' => ['_index' => $index, '_id' => (string)$entityId]];
            $body[] = $document;
        }
        $this->client()->bulkQuery(['body' => $body, 'refresh' => true]);
    }

    /**
     * The concrete engine clients (OpenSearch, Elasticsearch 7/8) share the index
     * management methods used here, but ClientInterface does not declare them.
     */
    private function client(): object
    {
        return $this->client ??= $this->clientResolver->create();
    }
}
