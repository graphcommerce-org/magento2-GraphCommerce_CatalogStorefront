<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Model\Storage\Client\Config;

use GraphCommerce\CatalogStorefront\Model\Storage\Client\Config\EntityConfigInterface;

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
