<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Deploy;

use Magento\Deploy\Model\DeploymentConfig\ChangeDetector;

/**
 * Remembers per process that the deployment configuration matches the hash
 * its import stored. Core reads that flag on every request; a deployment that
 * changes the configuration imports it and restarts the workers, so the first
 * request of a process answers for its lifetime. A detected change is not
 * kept, so the import lifts it without a restart.
 */
class ConfigChangeMemo
{
    /** @var array<string, false> */
    private array $unchanged = [];

    public function aroundHasChanges(ChangeDetector $subject, \Closure $proceed, $sectionName = null)
    {
        $key = (string)$sectionName;
        if (!isset($this->unchanged[$key])) {
            if ($proceed($sectionName)) {
                return true;
            }
            $this->unchanged[$key] = false;
        }

        return false;
    }
}
