<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\EntityMappings;

/**
 * One index per entity and store view, created on first write with the
 * fields the entity declares in `EntityMappings` and nothing else mapped:
 * every field stays in the source, so a rich document never meets the
 * mapping field limit. A mapping change needs the index dropped and the feed
 * re-exported.
 */
class Index
{
    /** @var array<string, true> */
    private array $ensured = [];

    public function __construct(
        private readonly Client $client,
        private readonly EntityMappings $mappings,
    ) {
    }

    public function ensure(string $entity, string $storeViewCode): string
    {
        $index = $this->client->indexName($entity, $storeViewCode);
        if (!isset($this->ensured[$index])) {
            if (!$this->client->indexExists($index)) {
                $this->client->createIndex($index, $this->mapping($this->mappings->fields($entity)));
            }
            $this->ensured[$index] = true;
        }

        return $index;
    }

    /**
     * @param array<string, string|array> $fields dotted field name to a type
     *   name, or to a nested spec (`type` nested with its `fields`)
     */
    private function mapping(array $fields): array
    {
        $mapping = ['dynamic' => false, 'properties' => []];
        foreach ($fields as $name => $type) {
            $property = is_array($type)
                ? ['type' => 'nested', 'properties' => $this->mapping((array)($type['fields'] ?? []))['properties']]
                : ['type' => $type];
            $target = &$mapping['properties'];
            $path = explode('.', $name);
            $leaf = array_pop($path);
            foreach ($path as $segment) {
                $target[$segment]['properties'] ??= [];
                $target = &$target[$segment]['properties'];
            }
            $target[$leaf] = $property;
            unset($target);
        }

        return $mapping;
    }
}
