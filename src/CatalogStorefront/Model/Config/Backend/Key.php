<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * The storefront key: a random value generated when the configuration is
 * saved with the field empty.
 */
class Key extends Value
{
    public function beforeSave(): self
    {
        if (trim((string)$this->getValue()) === '') {
            $this->setValue(bin2hex(random_bytes(16)));
        }

        return parent::beforeSave();
    }
}
