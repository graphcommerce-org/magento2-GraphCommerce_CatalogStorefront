<?php
/**
 * GraphQL parity harness.
 *
 * Runs every query in queries/ against the same endpoint twice: once on the
 * stock database path and once on the document path, by toggling the
 * serve_graphql flag. Reports PASS or a field-level diff per query. With
 * GC_WORKER_CONTAINER set and the attribution module enabled in the worker, a
 * document-path query that runs a SQL lookup fails too, with its statements:
 * the first rule of the module is that the request path runs none. Each
 * judged request carries a tag header, so its log line is found by tag. A
 * write is printed, not failed.
 *
 * Usage, from the Magento root, against the worker's own host name:
 *   GC_WORKER_CONTAINER=project-backend-frankenphp-1 \
 *   php packages/magento2-GraphCommerce_CatalogStorefront/dev/parity/run.php https://worker.localhost.reachdigital.io/graphql
 */
declare(strict_types=1);

$endpoint = $argv[1] ?? null;
if (!$endpoint) {
    fwrite(STDERR, "Usage: php run.php <graphql-endpoint>\n");
    exit(2);
}

$queryFiles = glob(__DIR__ . '/queries/*.graphql');
sort($queryFiles);
if (!$queryFiles) {
    fwrite(STDERR, "No queries found in queries/\n");
    exit(2);
}

/**
 * Orders that core leaves undefined are not document differences: aggregation
 * options tie-break in the search engine, and configurable_options come from a
 * collection without ORDER BY. Sort both so the diff sees the set, not the order.
 * Zero-count aggregation options are dropped: core's option provider joins
 * attributes by code across entity types, so an option of a same-named
 * attribute of another entity shows up on some runs. Aggregations are core on
 * both paths, so this hides no document difference.
 */
function normalize(mixed $node): mixed
{
    if (!is_array($node)) {
        return $node;
    }
    if (isset($node['aggregations']) && is_array($node['aggregations'])) {
        foreach ($node['aggregations'] as &$aggregation) {
            if (isset($aggregation['options']) && is_array($aggregation['options'])) {
                $aggregation['options'] = array_values(array_filter(
                    $aggregation['options'],
                    static fn($option) => ($option['count'] ?? 1) !== 0
                ));
                $aggregation['count'] = count($aggregation['options']);
                usort($aggregation['options'], static fn($a, $b) => ($a['value'] ?? '') <=> ($b['value'] ?? ''));
            }
        }
        unset($aggregation);
    }
    foreach ($node as $key => $child) {
        if (is_string($key) && str_ends_with($key, 'configurable_options') && is_array($child)) {
            usort($child, static fn($a, $b) => ($a['attribute_code'] ?? '') <=> ($b['attribute_code'] ?? ''));
            $node[$key] = $child;
        }
    }

    return array_map(normalize(...), $node);
}

function gql(string $endpoint, string $query, string $tag = ''): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-GC-Tag: $tag\r\n",
        'content' => json_encode(['query' => $query]),
        'ignore_errors' => true,
        'timeout' => 120,
    ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $body = file_get_contents($endpoint, false, $context);

    return json_decode((string)$body, true) ?? ['errors' => [['message' => 'invalid response: ' . $body]]];
}

function setFlag(int $value): void
{
    exec(sprintf('bin/magento config:set catalog/storefront_documents/serve_graphql %d 2>/dev/null', $value));
    // The GraphQL resolver cache must be cleared too, or a result cached under one
    // path is served under the other and parity passes falsely.
    exec('bin/magento cache:clean config graphql_query_resolver_result 2>/dev/null');
    // The worker container has its own cache database and holds the flag until restarted.
    if ($container = getenv('GC_WORKER_CONTAINER')) {
        exec(sprintf('docker exec %s php bin/magento cache:flush >/dev/null 2>&1; docker restart %s >/dev/null 2>&1', $container, $container));
        sleep(6);
    }
}

/**
 * The attribution module logs one GCATTR line per request in the worker, with
 * the SQL count and statements and the tag the request carried. The line
 * reaches the container log a moment after the response.
 */
function workerRequest(string $container, string $tag): ?array
{
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $log = (string)shell_exec(sprintf('docker logs --since 2m %s 2>&1', $container));
        if (preg_match_all('/GCATTR (\{.*?\})",/', $log, $matches)) {
            foreach ($matches[1] as $json) {
                $request = json_decode(str_replace(['\\"', '\\\\'], ['"', '\\'], $json), true);
                if (($request['tag'] ?? null) === $tag) {
                    return $request;
                }
            }
        }
        usleep(100000);
    }

    return null;
}

/**
 * @return string[] json-pointer-ish paths of differing leaves
 */
function diffPaths(mixed $a, mixed $b, string $path = ''): array
{
    if ($a === $b) {
        return [];
    }
    if (!is_array($a) || !is_array($b)) {
        return [sprintf('%s: %s != %s', $path ?: '/', json_encode($a), json_encode($b))];
    }
    $paths = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
        if (!array_key_exists($key, $a)) {
            $paths[] = sprintf('%s/%s: missing on stock path', $path, $key);
        } elseif (!array_key_exists($key, $b)) {
            $paths[] = sprintf('%s/%s: missing on document path', $path, $key);
        } else {
            $paths = array_merge($paths, diffPaths($a[$key], $b[$key], $path . '/' . $key));
        }
    }

    return $paths;
}

$stock = [];
setFlag(0);
foreach ($queryFiles as $file) {
    $stock[$file] = gql($endpoint, file_get_contents($file));
}
$document = [];
$sql = [];
setFlag(1);
$container = getenv('GC_WORKER_CONTAINER');
if ($container) {
    // Every query runs once unjudged: both worker threads take their cold request and the
    // caches the flush emptied fill up, so the gate sees the steady state.
    foreach (array_merge($queryFiles, $queryFiles) as $file) {
        gql($endpoint, file_get_contents($file));
    }
}
// Judged twice: a worker thread that has not served the shape yet fills its caches with a few
// lookups, and the gate judges the steady state, so the request with the fewer statements counts.
foreach ($queryFiles as $file) {
    foreach ([1, 2] as $attempt) {
        $tag = 'parity-' . basename($file, '.graphql') . '-' . getmypid() . '-' . $attempt;
        $response = gql($endpoint, file_get_contents($file), $tag);
        $document[$file] ??= $response;
        if ($container && ($request = workerRequest($container, $tag))) {
            if (!isset($sql[$file]) || count($request['sql_statements'] ?? []) < count($sql[$file][0]['sql_statements'] ?? [])) {
                $sql[$file] = [$request];
            }
        }
    }
}
// GC_PARITY_DUMP=<dir> keeps both responses per query for a closer look than the diff excerpt.
if ($dump = getenv('GC_PARITY_DUMP')) {
    @mkdir($dump, 0777, true);
    foreach ($queryFiles as $file) {
        $name = basename($file, '.graphql');
        file_put_contents("$dump/$name.stock.json", json_encode($stock[$file], JSON_PRETTY_PRINT));
        file_put_contents("$dump/$name.document.json", json_encode($document[$file], JSON_PRETTY_PRINT));
    }
}
$gate = $container && array_filter($sql);
if ($container && !$gate) {
    echo "NOTE  SQL gate skipped: enable GraphCommerce_CatalogStorefrontAttribution in the worker\n";
}

$failed = 0;
foreach ($queryFiles as $file) {
    $name = basename($file, '.graphql');
    // An error on either path is never parity, even when both paths fail alike.
    foreach (['stock' => $stock[$file], 'document' => $document[$file]] as $path => $response) {
        if (isset($response['errors'])) {
            $failed++;
            printf("ERROR %s (%s path): %s\n", $name, $path, substr(json_encode($response['errors'][0]['message'] ?? $response['errors']), 0, 200));
            continue 2;
        }
        // A query that returns no product compares nothing: a hidden product passes vacuously.
        if (($response['data']['products']['items'] ?? null) === []) {
            $failed++;
            printf("EMPTY %s (%s path): no products returned\n", $name, $path);
            continue 2;
        }
    }
    $statements = [];
    $writes = [];
    foreach ($sql[$file] ?? [] as $request) {
        foreach ((array)($request['sql_statements'] ?? []) as $statement => $count) {
            // A write is not a lookup: core records the search term's popularity on every search.
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement)) {
                $writes[$statement] = ($writes[$statement] ?? 0) + $count;
            } else {
                $statements[$statement] = ($statements[$statement] ?? 0) + $count;
            }
        }
    }
    foreach ($writes as $statement => $count) {
        printf("WRITE %s (document path): %dx %s\n", $name, $count, substr($statement, 0, 160));
    }
    if ($statements) {
        $failed++;
        printf("SQL   %s (document path): %d queries\n", $name, array_sum($statements));
        foreach (array_slice($statements, 0, 8, true) as $statement => $count) {
            printf("      %dx %s\n", $count, substr($statement, 0, 200));
        }
    }
    $diffs = diffPaths(normalize($stock[$file]), normalize($document[$file]));
    if (!$diffs) {
        if (!$statements) {
            printf("PASS  %s\n", $name);
        }
        continue;
    }
    $failed += $statements ? 0 : 1;
    printf("DIFF  %s (%d fields)\n", $name, count($diffs));
    foreach (array_slice($diffs, 0, 12) as $diff) {
        printf("      %s\n", substr($diff, 0, 220));
    }
    if (count($diffs) > 12) {
        printf("      ... and %d more\n", count($diffs) - 12);
    }
}

printf("\n%d of %d queries identical\n", count($queryFiles) - $failed, count($queryFiles));
exit($failed > 0 ? 1 : 0);
