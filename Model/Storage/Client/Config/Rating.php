<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage\Client\Config;

/**
 * Rating metadata documents are read all at once: nothing is mapped.
 */
class Rating implements EntityConfigInterface
{
    public const ENTITY_NAME = 'rating';

    public function getSettings(): array
    {
        return ['dynamic' => false];
    }
}
