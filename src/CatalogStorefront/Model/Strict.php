<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The report of one request: every fallback to core a document plugin took,
 * with its reason. Without the report, only an exception fallback leaves a
 * trace, as a warning in the log. A request with the storefront key gets the
 * fallbacks in the GraphQL response extensions.
 */
class Strict implements ResetAfterRequestInterface
{
    private ?bool $enabled = null;

    /** @var string[] */
    private array $fallbacks = [];

    public function __construct(
        private readonly StorefrontKey $key,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled ??= $this->key->granted();
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

    /**
     * @return array{fallbacks: string[]}
     */
    public function report(): array
    {
        return ['fallbacks' => $this->fallbacks];
    }

    public function _resetState(): void
    {
        $this->enabled = null;
        $this->fallbacks = [];
    }
}
