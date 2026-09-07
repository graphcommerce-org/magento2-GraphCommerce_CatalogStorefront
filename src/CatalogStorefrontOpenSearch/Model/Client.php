<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\ConfigurationMismatchException;
use Magento\OpenSearch\Model\SearchClient;
use OpenSearch\Client as OpenSearchClient;
use OpenSearch\Common\Exceptions\Missing404Exception;

/**
 * The document store on the search engine connection Magento is configured
 * with: core's OpenSearch client, taken from the engine resolver. Each
 * method is one request; a missing index reads as empty.
 */
class Client
{
    private const INDEX_PREFIX = 'catalog/storefront_documents/index_prefix';

    // Per list key: the stored ids minus the removed ones, plus the added ones once, compared as numbers.
    private const LIST_SCRIPT = <<<'PAINLESS'
        for (entry in params.changes.entrySet()) {
            def key = entry.getKey();
            List result = new ArrayList();
            if (ctx._source[key] != null) {
                for (v in ctx._source[key]) {
                    long id = ((Number) v).longValue();
                    boolean removed = false;
                    for (r in entry.getValue().remove) {
                        if (((Number) r).longValue() == id) { removed = true; }
                    }
                    if (!removed) { result.add((int) id); }
                }
            }
            for (a in entry.getValue().add) {
                long id = ((Number) a).longValue();
                boolean present = false;
                for (v in result) {
                    if (((Number) v).longValue() == id) { present = true; }
                }
                if (!present) { result.add((int) id); }
            }
            ctx._source[key] = result;
        }
        PAINLESS;

    private ?OpenSearchClient $client = null;

    public function __construct(
        private readonly ClientResolver $clientResolver,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    public function indexName(string $entity, string $storeViewCode): string
    {
        return $this->scopeConfig->getValue(self::INDEX_PREFIX) . '_' . $entity . '_' . $storeViewCode;
    }

    /**
     * Whether an index or an alias of that name exists.
     */
    public function indexExists(string $index): bool
    {
        return $this->client()->indices()->exists(['index' => $index]);
    }

    public function deleteIndex(string $index): void
    {
        try {
            $this->client()->indices()->delete(['index' => $index]);
        } catch (Missing404Exception) {
        }
    }

    /**
     * @param string[] $aliases the aliases the new index carries from the start
     */
    public function createIndex(string $index, array $mapping, array $aliases = []): void
    {
        $body = ['mappings' => $mapping];
        if ($aliases) {
            $body['aliases'] = array_fill_keys($aliases, new \stdClass());
        }
        $this->client()->indices()->create(['index' => $index, 'body' => $body]);
    }

    /**
     * @return string[] the indices behind an alias, none for a missing alias or a plain index
     */
    public function aliasTargets(string $alias): array
    {
        try {
            return array_keys($this->client()->indices()->getAlias(['name' => $alias]));
        } catch (Missing404Exception) {
            return [];
        }
    }

    /**
     * Points an alias at one index in a single atomic alias update.
     *
     * @param string[] $from the indices the alias leaves
     */
    public function moveAlias(string $alias, array $from, string $to): void
    {
        $actions = [];
        foreach ($from as $index) {
            $actions[] = ['remove' => ['index' => $index, 'alias' => $alias]];
        }
        $actions[] = ['add' => ['index' => $to, 'alias' => $alias]];
        $this->client()->indices()->updateAliases(['body' => ['actions' => $actions]]);
    }

    /**
     * @return array<string, array> the documents found, keyed by id
     */
    public function get(string $index, array $ids, array $fields): array
    {
        try {
            $result = $this->client()->mget([
                'index' => $index,
                'body' => ['ids' => array_values(array_map('strval', $ids))],
                '_source' => $fields ?: true,
            ]);
        } catch (Missing404Exception) {
            return [];
        }
        $documents = [];
        foreach ($result['docs'] ?? [] as $doc) {
            if (!empty($doc['found'])) {
                $documents[(string)$doc['_id']] = $doc['_source'];
            }
        }

        return $documents;
    }

    public function count(string $index): int
    {
        try {
            return (int)($this->client()->count(['index' => $index])['count'] ?? 0);
        } catch (Missing404Exception) {
            return 0;
        }
    }

    public function search(string $index, array $body): array
    {
        try {
            return $this->client()->search(['index' => $index, 'body' => $body]);
        } catch (Missing404Exception) {
            return [];
        }
    }

    /**
     * @param array[] $searches search bodies, all against the index
     * @return array[] one response per search, an empty one for a missing index
     */
    public function multiSearch(string $index, array $searches): array
    {
        $body = [];
        foreach ($searches as $search) {
            $body[] = ['index' => $index];
            $body[] = $search;
        }
        $responses = $this->client()->msearch(['body' => $body])['responses'] ?? [];

        return array_map(static fn(array $response) => isset($response['error']) ? [] : $response, $responses);
    }

    /**
     * Partial updates that create what is missing: objects merge, arrays and
     * scalars replace. The id travels in the source too, under `id`, so an
     * entity can declare it as a filterable field.
     *
     * @param array<int|string, array> $documents by id
     */
    public function upsert(string $index, array $documents): void
    {
        $body = [];
        foreach ($documents as $id => $document) {
            $body[] = ['update' => ['_index' => $index, '_id' => (string)$id, 'retry_on_conflict' => 3]];
            $body[] = ['doc' => ['id' => $id] + $document, 'doc_as_upsert' => true];
        }
        $this->bulk($body);
    }

    /**
     * Adds ids to and removes ids from id lists of stored documents, in the
     * store itself, so two feed threads that touch the same list cannot
     * lose each other's change. A missing document is created with the
     * added ids.
     *
     * @param array<int|string, array<string, array{add?: int[], remove?: int[]}>> $changes by id and list key
     */
    public function updateLists(string $index, array $changes): void
    {
        $body = [];
        foreach ($changes as $id => $byKey) {
            $upsert = ['id' => $id];
            $params = [];
            foreach ($byKey as $key => $change) {
                $params[$key] = ['add' => array_values($change['add'] ?? []), 'remove' => array_values($change['remove'] ?? [])];
                $upsert[$key] = $params[$key]['add'];
            }
            $body[] = ['update' => ['_index' => $index, '_id' => (string)$id, 'retry_on_conflict' => 3]];
            $body[] = [
                'script' => ['lang' => 'painless', 'source' => self::LIST_SCRIPT, 'params' => ['changes' => $params]],
                'upsert' => $upsert,
            ];
        }
        if ($body) {
            $this->bulk($body);
        }
    }

    public function delete(string $index, array $ids): void
    {
        $body = [];
        foreach ($ids as $id) {
            $body[] = ['delete' => ['_index' => $index, '_id' => (string)$id]];
        }
        $this->bulk($body);
    }

    private function bulk(array $body): void
    {
        $result = $this->client()->bulk(['body' => $body, 'refresh' => false]);
        if (empty($result['errors'])) {
            return;
        }
        $errors = [];
        foreach ($result['items'] ?? [] as $item) {
            $action = reset($item);
            if (isset($action['error']) && ($action['error']['type'] ?? '') !== 'index_not_found_exception') {
                $errors[] = sprintf('%s: %s %s', $action['_id'] ?? '?', $action['error']['type'] ?? '', $action['error']['reason'] ?? '');
            }
        }
        if ($errors) {
            throw new \RuntimeException('Document store bulk errors: ' . implode('; ', array_slice($errors, 0, 5)));
        }
    }

    private function client(): OpenSearchClient
    {
        if ($this->client === null) {
            $client = $this->clientResolver->create();
            if (!$client instanceof SearchClient) {
                throw new ConfigurationMismatchException(__('The catalog document store needs the OpenSearch search engine.'));
            }
            $this->client = $client->getOpenSearchClient();
        }

        return $this->client;
    }
}
