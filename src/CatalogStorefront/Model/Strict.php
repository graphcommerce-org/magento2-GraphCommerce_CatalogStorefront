<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The strict mode report of one request: every fallback to core a document
 * plugin took, with its reason, and every SQL statement the request ran,
 * counted. Off, only an exception fallback leaves a trace, as a warning in
 * the log. On, the GraphQL response carries the report in its extensions;
 * for test environments only, since it exposes statements.
 */
class Strict implements ResetAfterRequestInterface
{
    private ?bool $enabled = null;
    private bool $resolving = false;

    /** @var string[] */
    private array $fallbacks = [];

    /** @var array<string, int> */
    private array $statements = [];

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        if ($this->enabled === null) {
            // The config read runs SQL of its own on a cold cache; that SQL is not reported.
            if ($this->resolving) {
                return false;
            }
            $this->resolving = true;
            try {
                $this->enabled = $this->config->strict();
            } finally {
                $this->resolving = false;
            }
        }

        return $this->enabled;
    }

    /**
     * A document plugin hands the field to core because the document cannot answer.
     */
    public function fallback(string $source, string $reason): void
    {
        if ($this->enabled()) {
            $this->fallbacks[] = substr($source, strrpos($source, '\\') + 1) . ': ' . $reason;
        }
    }

    /**
     * A document plugin hands the field to core because it failed.
     */
    public function exception(string $source, \Throwable $e): void
    {
        $this->logger->warning(sprintf('catalog-storefront fallback in %s: %s', $source, $e->getMessage()));
        $this->fallback($source, 'exception: ' . $e->getMessage());
    }

    public function statement(string $sql): void
    {
        if ($this->enabled()) {
            $short = preg_replace('/\s+/', ' ', substr(trim($sql), 0, 200));
            $this->statements[$short] = ($this->statements[$short] ?? 0) + 1;
        }
    }

    /**
     * @return array{fallbacks: string[], sql: array<string, int>}
     */
    public function report(): array
    {
        arsort($this->statements);

        return ['fallbacks' => $this->fallbacks, 'sql' => $this->statements];
    }

    public function _resetState(): void
    {
        $this->enabled = null;
        $this->fallbacks = [];
        $this->statements = [];
    }
}
