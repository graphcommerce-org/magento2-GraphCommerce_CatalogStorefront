<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage\Client\Config;

/**
 * Attribute metadata documents are read by code or all at once: nothing is mapped.
 */
class Attribute implements EntityConfigInterface
{
    public const ENTITY_NAME = 'attribute';

    public function getSettings(): array
    {
        return ['dynamic' => false];
    }
}
