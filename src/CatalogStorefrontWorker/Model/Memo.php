<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Model;

/**
 * A process memo bound to one generation: the entries are dropped when the
 * generation changes, and when the memo reaches its limit.
 */
class Memo
{
    private string $generation = '';

    /** @var array<string, mixed> */
    private array $entries = [];

    public function __construct(
        private readonly Generation $generations,
        private readonly string $name,
        private readonly int $limit = 5000,
    ) {
    }

    public function get(string $key, callable $compute): mixed
    {
        $generation = $this->generations->current($this->name);
        if ($generation !== $this->generation || count($this->entries) >= $this->limit) {
            $this->entries = [];
            $this->generation = $generation;
        }
        if (!array_key_exists($key, $this->entries)) {
            $this->entries[$key] = $compute();
        }

        return $this->entries[$key];
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }
}
