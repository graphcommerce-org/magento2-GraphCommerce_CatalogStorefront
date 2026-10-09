<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontStatementGate\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The SQL statements of one request, counted by their first 200 characters.
 */
class Statements implements ResetAfterRequestInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    public function record(string $sql): void
    {
        $short = preg_replace('/\s+/', ' ', substr(trim($sql), 0, 200));
        $this->counts[$short] = ($this->counts[$short] ?? 0) + 1;
    }

    /**
     * @return array<string, int> most frequent first
     */
    public function counts(): array
    {
        arsort($this->counts);

        return $this->counts;
    }

    public function _resetState(): void
    {
        $this->counts = [];
    }
}
