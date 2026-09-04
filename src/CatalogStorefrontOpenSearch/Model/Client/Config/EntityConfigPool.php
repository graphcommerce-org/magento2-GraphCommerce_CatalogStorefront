<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model\Client\Config;

/**
 * The index mapping per entity name (di.xml `configs`); an entity without one
 * maps nothing.
 */
class EntityConfigPool
{
    /**
     * @param EntityConfigInterface[] $configs by entity name
     */
    public function __construct(
        private readonly Unmapped $unmapped,
        private readonly array $configs = [],
    ) {
    }

    public function getConfig(string $entityName): EntityConfigInterface
    {
        return $this->configs[$entityName] ?? $this->unmapped;
    }
}
