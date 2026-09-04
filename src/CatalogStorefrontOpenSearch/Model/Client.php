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

    public function createIndex(string $index, array $mapping): void
    {
        $this->client()->indices()->create(['index' => $index, 'body' => ['mappings' => $mapping]]);
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
            $body[] = ['update' => ['_index' => $index, '_id' => (string)$id]];
            $body[] = ['doc' => ['id' => $id] + $document, 'doc_as_upsert' => true];
        }
        $this->bulk($body);
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
