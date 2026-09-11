<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontApi\Service;

/** Imported metadata for one canonical Source. No native attribute fallback. */
interface SourceMetadataInterface
{
    /** @return array{sourceId: int, scope: string, available: bool, attributes: array} */
    public function describe(int $sourceId): array;
}
