<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAttribution\Plugin;

class Context
{
    public function aroundCreate($subject, \Closure $proceed, ...$args)
    {
        return Outer::timed('context_create', $proceed, $args);
    }
}
