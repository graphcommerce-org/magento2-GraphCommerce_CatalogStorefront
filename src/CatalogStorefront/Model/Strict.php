<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/** Document read failures stop the request. Keyed requests also carry the failure report. */
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

    public function fallback(string $source, string $reason, ?\Throwable $previous = null): never
    {
        $separator = strrpos($source, '\\');
        $message = ($separator === false ? $source : substr($source, $separator + 1)) . ': ' . $reason;
        if ($this->enabled()) {
            $this->fallbacks[] = $message;
        }
        throw new DocumentReadException('Catalog document read failed in ' . $message, 0, $previous);
    }

    public function exception(string $source, \Throwable $e): never
    {
        if ($e instanceof DocumentReadException) {
            throw $e;
        }
        $this->logger->error(sprintf('catalog-storefront document read in %s: %s', $source, $e->getMessage()), ['exception' => $e]);
        $this->fallback($source, $e->getMessage(), $e);
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
