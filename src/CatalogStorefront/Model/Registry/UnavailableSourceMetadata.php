<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefront\Model\Registry;

use GraphCommerce\CatalogStorefrontApi\Service\SourceMetadataInterface;

/** A Policy attribute picker states that a Source describes no attributes; it never reads EAV. */
class UnavailableSourceMetadata implements SourceMetadataInterface
{
    public function describe(int $sourceId): array
    {
        return ['sourceId' => $sourceId, 'available' => false, 'attributes' => []];
    }
}
