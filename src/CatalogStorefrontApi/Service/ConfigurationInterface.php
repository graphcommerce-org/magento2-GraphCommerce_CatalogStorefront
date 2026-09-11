<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontApi\Service;

use GraphCommerce\CatalogStorefrontApi\Registry\ResourceRepositoryInterface;

/**
 * Configuration service v1. Values crossing this boundary are JSON-compatible
 * arrays/scalars, never native models, connections or iterators. The current
 * installation scopes the local binding; remote tenant/auth context is transport work.
 * Mutations persist configuration; they do not promise a published serving revision.
 */
interface ConfigurationInterface extends ResourceRepositoryInterface
{
    /** @return array{mode: string, can_manage: bool, message: string} Presentation capabilities, not authorization. */
    public function capabilities(): array;
    public function bookCurrency(int $bookId): string;
}
