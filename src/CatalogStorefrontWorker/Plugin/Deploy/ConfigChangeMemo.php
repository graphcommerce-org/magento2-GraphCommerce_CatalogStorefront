<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Deploy;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use Magento\Deploy\Model\DeploymentConfig\ChangeDetector;

/**
 * Remembers under the config generation that the deployment configuration
 * matches the hash its import stored. Core reads that flag from the database
 * on every request. A detected change is not kept, so the import lifts it.
 */
class ConfigChangeMemo
{
    private readonly Memo $unchanged;

    public function __construct(MemoFactory $memoFactory)
    {
        $this->unchanged = $memoFactory->create(['name' => Generation::CONFIG]);
    }

    public function aroundHasChanges(ChangeDetector $subject, \Closure $proceed, $sectionName = null)
    {
        $key = (string)$sectionName;
        $changed = $this->unchanged->get($key, static fn(): bool => (bool)$proceed($sectionName));
        if ($changed) {
            $this->unchanged->forget($key);
        }

        return $changed;
    }
}
