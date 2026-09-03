<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAttribution\Plugin;

class Inner
{
    public static array $inner = [];

    public function aroundResolve($subject, \Closure $proceed, ...$args)
    {
        $class = get_parent_class($subject) && str_ends_with(get_class($subject), '\Interceptor') ? get_parent_class($subject) : get_class($subject);
        $t = hrtime(true);
        try {
            return $proceed(...$args);
        } finally {
            self::$inner[$class]['ms'] = (self::$inner[$class]['ms'] ?? 0) + (hrtime(true) - $t) / 1e6;
            self::$inner[$class]['n'] = (self::$inner[$class]['n'] ?? 0) + 1;
        }
    }
}
