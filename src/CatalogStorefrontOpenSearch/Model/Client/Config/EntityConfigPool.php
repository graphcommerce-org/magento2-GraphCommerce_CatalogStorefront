<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model\Client\Config;

use GraphCommerce\CatalogStorefrontApi\Storage\EntityMappings;

/**
 * The index mapping per entity name: the product mapping (di.xml `configs`),
 * and for every other entity the fields it declares in `EntityMappings`.
 */
class EntityConfigPool
{
    /**
     * @param EntityConfigInterface[] $configs by entity name
     */
    public function __construct(
        private readonly EntityMappings $mappings,
        private readonly DeclaredFactory $declaredFactory,
        private readonly array $configs = [],
    ) {
    }

    public function getConfig(string $entityName): EntityConfigInterface
    {
        return $this->configs[$entityName]
            ?? $this->declaredFactory->create(['fields' => $this->mappings->fields($entityName)]);
    }
}
