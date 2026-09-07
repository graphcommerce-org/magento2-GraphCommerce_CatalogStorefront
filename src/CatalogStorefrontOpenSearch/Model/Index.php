<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model;

use GraphCommerce\CatalogStorefrontApi\Storage\EntityMappings;

/**
 * One index per entity and store view behind two aliases: the entity name
 * (`<prefix>_<entity>_<store view>`) serves the reads, its `_write` twin
 * takes the writes. The index is created on first write with the fields the
 * entity declares in `EntityMappings` and nothing else mapped: every field
 * stays in the source, so a rich document never meets the mapping field
 * limit. A rebuild stages a fresh index behind the write alias, so the feeds
 * fill it while the reads keep the current one, and promotes it when the
 * feeds are through: the read alias moves in one step and the old index is
 * deleted. An index created before the aliases, named as the read alias,
 * keeps taking the writes until a rebuild replaces it.
 */
class Index
{
    private const WRITE = '_write';

    /** @var array<string, string> the write target per read name */
    private array $ensured = [];

    public function __construct(
        private readonly Client $client,
        private readonly EntityMappings $mappings,
    ) {
    }

    /**
     * @return string the index or alias a write goes to
     */
    public function ensure(string $entity, string $storeViewCode): string
    {
        $name = $this->client->indexName($entity, $storeViewCode);
        if (!isset($this->ensured[$name])) {
            $write = $name . self::WRITE;
            if ($this->client->indexExists($write)) {
                $this->ensured[$name] = $write;
            } elseif ($this->client->indexExists($name)) {
                $this->ensured[$name] = $name;
            } else {
                $this->client->createIndex($this->fresh($name), $this->mapping($this->mappings->fields($entity)), [$name, $write]);
                $this->ensured[$name] = $write;
            }
        }

        return $this->ensured[$name];
    }

    /**
     * A fresh, empty index takes the writes from now on; the reads stay where
     * they are. A staged index that was never promoted is deleted.
     */
    public function stage(string $entity, string $storeViewCode): void
    {
        $name = $this->client->indexName($entity, $storeViewCode);
        $write = $name . self::WRITE;
        $index = $this->fresh($name);
        $this->client->createIndex($index, $this->mapping($this->mappings->fields($entity)));
        $writes = $this->client->aliasTargets($write);
        $this->client->moveAlias($write, $writes, $index);
        foreach (array_diff($writes, $this->client->aliasTargets($name)) as $abandoned) {
            $this->client->deleteIndex($abandoned);
        }
        $this->ensured[$name] = $write;
    }

    /**
     * The index behind the write alias serves the reads from now on; the
     * index it replaces is deleted.
     */
    public function promote(string $entity, string $storeViewCode): void
    {
        $name = $this->client->indexName($entity, $storeViewCode);
        $target = $this->client->aliasTargets($name . self::WRITE)[0] ?? null;
        if ($target === null) {
            return;
        }
        $reads = $this->client->aliasTargets($name);
        if (!$reads && $this->client->indexExists($name)) {
            $this->client->deleteIndex($name);
        }
        $this->client->moveAlias($name, $reads, $target);
        foreach (array_diff($reads, [$target]) as $old) {
            $this->client->deleteIndex($old);
        }
    }

    private function fresh(string $name): string
    {
        return $name . '_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(2));
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
