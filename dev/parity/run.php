<?php
/**
 * GraphQL parity harness.
 *
 * Runs every query in queries/ against the same endpoint twice: once on the
 * stock database path and once on the document path, by toggling the
 * serve_reads flag. Reports PASS or a field-level diff per query.
 *
 * Usage, from the Magento root:
 *   php app/code/GraphCommerce/CatalogStorefront/dev/parity/run.php <endpoint>
 * Example endpoint: https://backend.localhost.reachdigital.io/graphql
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
    if (isset($node['configurable_options']) && is_array($node['configurable_options'])) {
        usort($node['configurable_options'], static fn($a, $b) => ($a['attribute_code'] ?? '') <=> ($b['attribute_code'] ?? ''));
    }

    return array_map(normalize(...), $node);
}

function gql(string $endpoint, string $query): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['query' => $query]),
        'ignore_errors' => true,
        'timeout' => 120,
    ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $body = file_get_contents($endpoint, false, $context);

    return json_decode((string)$body, true) ?? ['errors' => [['message' => 'invalid response: ' . $body]]];
}

function setFlag(int $value): void
{
    exec(sprintf('bin/magento config:set graphcommerce/catalog_storefront/serve_reads %d 2>/dev/null', $value));
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
setFlag(1);
foreach ($queryFiles as $file) {
    $document[$file] = gql($endpoint, file_get_contents($file));
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
    $diffs = diffPaths(normalize($stock[$file]), normalize($document[$file]));
    if (!$diffs) {
        printf("PASS  %s\n", $name);
        continue;
    }
    $failed++;
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
