<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;

/**
 * Bumps one generation after a save or delete on the repository it is
 * registered on (di.xml: a virtual type per generation), so every memo built
 * under it refills.
 */
class Bump
{
    public function __construct(
        private readonly Generation $generations,
        private readonly string $name,
    ) {
    }

    public function afterSave($subject, $result)
    {
        $this->generations->bump($this->name);

        return $result;
    }

    public function afterDelete($subject, $result)
    {
        $this->generations->bump($this->name);

        return $result;
    }

    public function afterDeleteById($subject, $result)
    {
        $this->generations->bump($this->name);

        return $result;
    }

    public function afterSaveRates($subject, $result)
    {
        $this->generations->bump($this->name);

        return $result;
    }
}
