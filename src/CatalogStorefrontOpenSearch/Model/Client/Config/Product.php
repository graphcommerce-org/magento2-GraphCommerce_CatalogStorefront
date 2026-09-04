<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Model\Client\Config;

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
        // A document store: keep every field in _source and map only what a
        // request filters or aggregates on, so the 1000-field mapping limit
        // never applies to rich product documents. priceIndex holds one small
        // object per customer group key and maps its floats dynamically.
        return [
            'dynamic' => false,
            'properties' => [
                'sku' => ['type' => 'keyword'],
                'type' => ['type' => 'keyword'],
                'parentIds' => ['type' => 'keyword'],
                'groupedParentIds' => ['type' => 'keyword'],
                'bundleParentIds' => ['type' => 'keyword'],
                'status' => ['type' => 'keyword'],
                'stock' => ['properties' => ['isSalable' => ['type' => 'boolean']]],
                'priceIndex' => ['type' => 'object', 'dynamic' => true],
            ],
        ];
    }
}
