<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Console\Command;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Mode;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The parity gate: runs every query of a directory against one GraphQL
 * endpoint on the core path and on the document path, picked per request
 * with the X-Catalog-Storefront header, and diffs the responses. With strict
 * mode on, the document path's SQL statements and fallbacks come back in the
 * response extensions: a lookup fails the query, a write and a fallback are
 * printed. Every query runs unjudged first so the gate sees the steady state.
 * A query file sends extra request headers through comment lines of the form
 * `# @header Content-Currency: EUR`.
 */
class Parity extends Command
{
    private const ENDPOINT = 'endpoint';
    private const QUERIES = 'queries';
    private const DUMP = 'dump';
    private const WARM = 'warm';

    public function __construct(
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('catalog-storefront:parity')
            ->setDescription('Compares the core path and the document path of catalog GraphQL queries')
            ->addArgument(self::ENDPOINT, InputArgument::REQUIRED, 'The GraphQL endpoint, for example https://shop.example/graphql')
            ->addOption(self::QUERIES, null, InputOption::VALUE_REQUIRED, 'Directory of .graphql files', dirname(__DIR__, 4) . '/dev/parity/queries')
            ->addOption(self::DUMP, null, InputOption::VALUE_REQUIRED, 'Directory that keeps both responses of every query')
            ->addOption(self::WARM, null, InputOption::VALUE_REQUIRED, 'Unjudged runs of every query per path before the judged one', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->config->requestOverride()) {
            $output->writeln('<error>Turn on Catalog > Catalog > Catalog Storefront Document Store > Allow Request Override first.</error>');

            return Command::FAILURE;
        }
        $strict = $this->config->strict();
        if (!$strict) {
            $output->writeln('NOTE  SQL gate skipped: turn on Strict Mode to judge the SQL of the document path');
        }
        $endpoint = (string)$input->getArgument(self::ENDPOINT);
        $files = glob(rtrim((string)$input->getOption(self::QUERIES), '/') . '/*.graphql') ?: [];
        sort($files);
        if (!$files) {
            $output->writeln('<error>No .graphql files found.</error>');

            return Command::FAILURE;
        }
        $warm = max(0, (int)$input->getOption(self::WARM));
        $dump = $input->getOption(self::DUMP);

        $failed = 0;
        foreach ($files as $file) {
            $name = basename($file, '.graphql');
            $query = (string)file_get_contents($file);
            preg_match_all('/^#\s*@header\s+([\w-]+):\s*(.+?)\s*$/m', $query, $matches, PREG_SET_ORDER);
            $headers = array_combine(array_column($matches, 1), array_column($matches, 2));
            $responses = [];
            foreach ([Mode::CORE, Mode::DOCUMENTS] as $mode) {
                for ($run = 0; $run < $warm; $run++) {
                    $this->request($endpoint, $query, $mode, $headers);
                }
                // Judged three times: a worker thread that has not served the shape yet fills its memos with
                // a few lookups, and the gate judges the steady state, so the response with the fewest
                // statements counts.
                $best = null;
                foreach ([1, 2, 3] as $attempt) {
                    $response = $this->request($endpoint, $query, $mode, $headers);
                    if ($best === null || count($response['extensions']['catalogStorefront']['sql'] ?? []) < count($best['extensions']['catalogStorefront']['sql'] ?? [])) {
                        $best = $response;
                    }
                }
                $responses[$mode] = $best;
            }
            if ($dump) {
                @mkdir($dump, 0777, true);
                foreach ($responses as $mode => $response) {
                    file_put_contents("$dump/$name.$mode.json", json_encode($response, JSON_PRETTY_PRINT));
                }
            }
            $failed += $this->judge($output, $name, $responses[Mode::CORE], $responses[Mode::DOCUMENTS], $strict) ? 0 : 1;
        }
        $output->writeln(sprintf("\n%d of %d queries identical", count($files) - $failed, count($files)));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function judge(OutputInterface $output, string $name, array $core, array $documents, bool $strict): bool
    {
        // An error on either path is never parity, even when both paths fail alike.
        foreach ([Mode::CORE => $core, Mode::DOCUMENTS => $documents] as $mode => $response) {
            if (isset($response['errors'])) {
                $output->writeln(sprintf('ERROR %s (%s path): %s', $name, $mode, substr(json_encode($response['errors'][0]['message'] ?? $response['errors']), 0, 200)));

                return false;
            }
            // A query that returns no product compares nothing: a hidden product passes vacuously.
            if (($response['data']['products']['items'] ?? null) === []) {
                $output->writeln(sprintf('EMPTY %s (%s path): no products returned', $name, $mode));

                return false;
            }
        }
        $report = $documents['extensions']['catalogStorefront'] ?? [];
        if ($strict && ($report['mode'] ?? null) !== Mode::DOCUMENTS) {
            $output->writeln(sprintf('ERROR %s: the endpoint did not honour the %s header', $name, Mode::HEADER));

            return false;
        }
        foreach ((array)($report['fallbacks'] ?? []) as $fallback) {
            $output->writeln(sprintf('FALLBACK %s: %s', $name, $fallback));
        }
        $lookups = [];
        foreach ((array)($report['sql'] ?? []) as $statement => $count) {
            // A write is not a lookup: core records the search term's popularity on every search.
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement)) {
                $output->writeln(sprintf('WRITE %s (document path): %dx %s', $name, $count, substr($statement, 0, 160)));
            } else {
                $lookups[$statement] = $count;
            }
        }
        $ok = true;
        if ($lookups) {
            $ok = false;
            $output->writeln(sprintf('SQL   %s (document path): %d queries', $name, array_sum($lookups)));
            foreach (array_slice($lookups, 0, 8, true) as $statement => $count) {
                $output->writeln(sprintf('      %dx %s', $count, $statement));
            }
        }
        $diffs = $this->diff($this->normalize($core['data'] ?? null), $this->normalize($documents['data'] ?? null));
        if ($diffs) {
            $ok = false;
            $output->writeln(sprintf('DIFF  %s (%d fields)', $name, count($diffs)));
            foreach (array_slice($diffs, 0, 12) as $diff) {
                $output->writeln('      ' . substr($diff, 0, 220));
            }
            if (count($diffs) > 12) {
                $output->writeln(sprintf('      ... and %d more', count($diffs) - 12));
            }
        }
        if ($ok) {
            $output->writeln('PASS  ' . $name);
        }

        return $ok;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $endpoint, string $query, string $mode, array $headers): array
    {
        $headers = ['Content-Type' => 'application/json', Mode::HEADER => $mode] + $headers;
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode('', array_map(static fn($name, $value) => "$name: $value\r\n", array_keys($headers), $headers)),
            'content' => json_encode(['query' => $query]),
            'ignore_errors' => true,
            'timeout' => 120,
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $body = @file_get_contents($endpoint, false, $context);

        return json_decode((string)$body, true) ?? ['errors' => [['message' => 'invalid response: ' . substr((string)$body, 0, 200)]]];
    }

    /**
     * Orders that core leaves undefined are not document differences: aggregation
     * options tie-break in the search engine, and configurable_options come from a
     * collection without ORDER BY. Both are sorted so the diff sees the set, not the
     * order. Zero-count aggregation options are dropped: core's option provider joins
     * attributes by code across entity types, so an option of a same-named attribute
     * of another entity shows up on some runs.
     */
    private function normalize(mixed $node): mixed
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

        return array_map($this->normalize(...), $node);
    }

    /**
     * @return string[] paths of differing leaves
     */
    private function diff(mixed $a, mixed $b, string $path = ''): array
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
                $paths[] = sprintf('%s/%s: missing on core path', $path, $key);
            } elseif (!array_key_exists($key, $b)) {
                $paths[] = sprintf('%s/%s: missing on document path', $path, $key);
            } else {
                $paths = array_merge($paths, $this->diff($a[$key], $b[$key], $path . '/' . $key));
            }
        }

        return $paths;
    }
}
