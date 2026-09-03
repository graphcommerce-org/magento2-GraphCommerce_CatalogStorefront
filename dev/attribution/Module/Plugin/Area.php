<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAttribution\Plugin;

class Area
{
    public function aroundLoad($subject, \Closure $proceed, ...$args)
    {
        return Outer::timed('area_load', $proceed, $args);
    }
}
