<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Storage\MetadataDocumentStorage;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The store view's attribute documents, loaded once per request: labels,
 * frontend input, layer position, filterable mode and the options with their
 * labels, from the product attributes feed.
 */
class AttributeMetadata implements ResetAfterRequestInterface
{
    /** @var array<string, array[]> attribute documents per store view keyed by attribute code */
    private array $byStore = [];

    public function __construct(
        private readonly MetadataDocumentStorage $storage,
    ) {
    }

    /**
     * @return array[] keyed by attribute code; empty when the feed has not landed
     */
    public function all(string $storeViewCode): array
    {
        return $this->byStore[$storeViewCode] ??= $this->storage->all($storeViewCode);
    }

    public function get(string $storeViewCode, string $attributeCode): ?array
    {
        return $this->all($storeViewCode)[$attributeCode] ?? null;
    }

    public function _resetState(): void
    {
        $this->byStore = [];
    }
}
