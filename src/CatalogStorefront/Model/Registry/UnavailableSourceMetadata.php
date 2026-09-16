<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefront\Model\Registry;

use GraphCommerce\CatalogStorefrontApi\Service\SourceMetadataInterface;

/** Cloud policy pickers have no local EAV fallback when a service is unavailable. */
class UnavailableSourceMetadata implements SourceMetadataInterface
{
    public function describe(int $sourceId): array
    {
        return ['sourceId' => $sourceId, 'available' => false, 'attributes' => []];
    }
}
