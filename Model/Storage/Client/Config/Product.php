<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage\Client\Config;

use Magento\Framework\App\DeploymentConfig\Reader;
use Magento\Framework\Config\File\ConfigFilePool;

/**
 * Product entity type config.
 */
class Product implements EntityConfigInterface
{
    /**
     * Entity name. Used to hold configuration for specific entity type and as a part of the storage name
     */
    public const ENTITY_NAME = 'product';

    /**
     * @inheritdoc
     */
    public function getSettings() : array
    {
        // A document store: keep every field in _source, map none, so the
        // 1000-field mapping limit never applies to rich product documents.
        return [
            'dynamic' => false,
        ];
    }
}
