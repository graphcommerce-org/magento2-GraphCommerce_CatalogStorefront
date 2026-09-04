<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model\Client\Config;

/**
 * The mapping of an entity read by id or all at once: every field stays in
 * the source, none is mapped.
 */
class Unmapped implements EntityConfigInterface
{
    public function getSettings(): array
    {
        return ['dynamic' => false];
    }
}
