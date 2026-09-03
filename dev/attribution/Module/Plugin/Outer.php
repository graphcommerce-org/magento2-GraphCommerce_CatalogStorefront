<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAttribution\Plugin;

/**
 * Logs one GCATTR line per request with the time inside every layer: launch,
 * GraphQL dispatch, parse, schema, execution, each resolver class (self time,
 * nested resolver time subtracted), the search adapter and OpenSearch client
 * calls with the server's took, the document listing and model build, SQL,
 * Redis loads and ResolveInfo creation. Static state is per worker thread and
 * reset at launch. Adds about 5ms to a 200-item listing.
 */
class Outer
{
    private static array $acc = [];
    private static array $took = [];
    private static array $resolverStack = [];
    private static array $resolverSelf = [];
    private static float $resolverTopLevel = 0;
    private static float $launchStart = 0;

    private static function time(string $key, \Closure $proceed, array $args)
    {
        $t = hrtime(true);
        try {
            return $proceed(...$args);
        } finally {
            self::$acc[$key]['ms'] = (self::$acc[$key]['ms'] ?? 0) + (hrtime(true) - $t) / 1e6;
            self::$acc[$key]['n'] = (self::$acc[$key]['n'] ?? 0) + 1;
        }
    }

    public function aroundLaunch($subject, \Closure $proceed)
    {
        self::$acc = [];
        self::$took = [];
        self::$resolverStack = [];
        self::$resolverSelf = [];
        self::$resolverTopLevel = 0;
        Inner::$inner = [];
        self::$launchStart = hrtime(true);
        $preLaunch = microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT'];
        try {
            return $proceed();
        } finally {
            $out = ['pre_launch' => round($preLaunch * 1000, 2), 'launch' => round((hrtime(true) - self::$launchStart) / 1e6, 2)];
            foreach (self::$acc as $k => $v) {
                $out[$k] = [round($v['ms'], 2), $v['n']];
            }
            $out['resolvers_top_level'] = round(self::$resolverTopLevel, 2);
            arsort(self::$resolverSelf);
            $out['resolver_self'] = array_map(static fn($v) => [round($v['ms'], 2), $v['n']], self::$resolverSelf);
            $out['resolver_inner'] = array_map(static fn($v) => [round($v['ms'], 2), $v['n']], Inner::$inner);
            $out['took'] = self::$took;
            $out['mem_peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
            error_log('GCATTR ' . json_encode($out));
        }
    }

    public function aroundDispatch($subject, \Closure $proceed, ...$args)
    {
        return self::time('graphql_dispatch', $proceed, $args);
    }

    public function aroundProcess($subject, \Closure $proceed, ...$args)
    {
        return self::time('graphql_process', $proceed, $args);
    }

    public function aroundParse($subject, \Closure $proceed, ...$args)
    {
        return self::time('graphql_parse', $proceed, $args);
    }

    public function aroundGenerate($subject, \Closure $proceed, ...$args)
    {
        return self::time('graphql_schema', $proceed, $args);
    }

    public function aroundResolve($subject, \Closure $proceed, ...$args)
    {
        $class = get_parent_class($subject) && str_ends_with(get_class($subject), '\Interceptor') ? get_parent_class($subject) : get_class($subject);
        $t = hrtime(true);
        self::$resolverStack[] = 0.0;
        try {
            return $proceed(...$args);
        } finally {
            $inclusive = (hrtime(true) - $t) / 1e6;
            $children = array_pop(self::$resolverStack);
            if (self::$resolverStack) {
                self::$resolverStack[count(self::$resolverStack) - 1] += $inclusive;
            } else {
                self::$resolverTopLevel += $inclusive;
            }
            self::$resolverSelf[$class]['ms'] = (self::$resolverSelf[$class]['ms'] ?? 0) + $inclusive - $children;
            self::$resolverSelf[$class]['n'] = (self::$resolverSelf[$class]['n'] ?? 0) + 1;
        }
    }

    public function aroundQuery($subject, \Closure $proceed, ...$args)
    {
        if ($subject instanceof \Magento\Framework\DB\Adapter\Pdo\Mysql) {
            return self::time('sql', $proceed, $args);
        }
        if ($subject instanceof \Magento\OpenSearch\Model\OpenSearch) {
            $r = self::time('os_core_client', $proceed, $args);
            $b = $args[0]['body'] ?? [];
            self::$took[] = 'core:' . ($r['took'] ?? '?') . 'ms:' . count($b['aggregations'] ?? $b['aggs'] ?? []) . 'aggs';
            return $r;
        }
        return self::time('search_adapter', $proceed, $args);
    }

    public function aroundMultiSearch($subject, \Closure $proceed, ...$args)
    {
        $r = self::time('os_doc_client', $proceed, $args);
        foreach ($r as $resp) {
            self::$took[] = 'doc:' . ($resp['took'] ?? '?') . 'ms';
        }
        return $r;
    }

    public function aroundGetList($subject, \Closure $proceed, ...$args)
    {
        return self::time('products_get_list', $proceed, $args);
    }

    public function aroundListing($subject, \Closure $proceed, ...$args)
    {
        return self::time('doc_listing', $proceed, $args);
    }

    public function aroundBuildModels($subject, \Closure $proceed, ...$args)
    {
        return self::time('doc_build_models', $proceed, $args);
    }

    public function aroundCreate($subject, \Closure $proceed, ...$args)
    {
        return self::time('resolve_info_create', $proceed, $args);
    }

    public function aroundLoad($subject, \Closure $proceed, ...$args)
    {
        return self::time('redis_load', $proceed, $args);
    }
}
