<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefront\Model\Registry;

use GraphCommerce\CatalogStorefrontApi\Service\SourceMetadataInterface;
use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface;

/** Cloud policy pickers have no local EAV fallback when a service is unavailable. */
class UnavailableSourceMetadata implements SourceMetadataInterface
{
    public function __construct(private readonly ConfigurationInterface $configuration) {}
    public function describe(int $sourceId): array
    {
        $source = $this->configuration->get('sources', $sourceId);
        return ['sourceId' => $sourceId, 'scope' => (string)$source['document_scope'], 'available' => false, 'attributes' => []];
    }
}
