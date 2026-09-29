<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Console\Command;

use GraphCommerce\CatalogStorefrontGraphQlApi\Parity\JudgeInterface;
use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Parity\Picks;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\GraphQl\Query\Uid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The parity gate: runs every query of a directory against a reference GraphQL
 * endpoint on the core path and a candidate (by default the same endpoint) on the document path, picked per request
 * with the X-Catalog-Storefront header under the storefront key, and diffs
 * the responses. The document path's fallbacks come back in the response
 * extensions and are printed. Every query runs unjudged first so the gate
 * sees the steady state, and a query that fails is requested again up to
 * `--attempts` times before its verdict counts: a worker thread that has not
 * served the shape yet answers from a cold state once. A query file sends extra request headers through
 * comment lines of the form `# @header Content-Currency: EUR` and names its
 * products and categories through placeholders that [`Picks`](../../Model/Parity/Picks.php)
 * resolves from the catalog of the installation: `{{sku:<kind>}}`,
 * `{{skus:<kind>:<count>}}`, `{{url_key:<kind>}}`, `{{category_id:<kind>}}`,
 * `{{category_url_path:<kind>}}`, `{{option_uid:<kind>}}` (the first option
 * of the first super attribute of that configurable product, or
 * `{{option_uid:<attribute code>:<admin label>}}` for a named one),
 * `{{search_term}}` and `{{cart_id}}` (a guest cart the run creates on the
 * reference endpoint with two of the first simple product, a child of a
 * configurable one where none is visible). A query whose placeholder
 * the catalog cannot fill is skipped. `# @requires Authorization` states a
 * header the run must send, `# @requires !Authorization` one it must not
 * send, and `# @requires-field ProductInterface.activity` a field the schema
 * of the reference endpoint must hold; a query whose requirement the run
 * does not meet is skipped. `--header`
 * sends one with every query, a customer token for a signed-in gate. Other
 * modules add verdicts through di.xml `judges`. `--soak` runs the accepted
 * queries again for as many requests, with the customer, the page size, the
 * sku list and the order of the two paths varied per request, and
 * `--memory-sample` samples the worker's memory while it does.
 */
class Parity extends Command
{
    private const ENDPOINT = 'endpoint';
    private const QUERIES = 'queries';
    private const DUMP = 'dump';
    private const WARM = 'warm';
    private const HEADER = 'header';
    private const ATTEMPTS = 'attempts';
    private const CANDIDATE_ENDPOINT = 'candidate-endpoint';
    private const REPORT = 'report';
    private const INSECURE = 'insecure';
    private const SOAK = 'soak';
    private const TOKEN = 'token';
    private const TOKEN_FILE = 'token-file';
    private const PROBE_HEADER = 'probe-header';
    private const PROBE_SHARE = 'probe-share';
    private const MEMORY_SAMPLE = 'memory-sample';
    private const MEMORY_SLOPE = 'memory-slope';
    private const WORKER_CONTAINER = 'worker-container';
    private const WORKER_PROCESS = 'worker-process';

    private bool $verifyPeer = true;

    /**
     * @param JudgeInterface[] $judges
     */
    public function __construct(
        private readonly Config $config,
        private readonly EavConfig $eavConfig,
        private readonly Uid $uidEncoder,
        private readonly Picks $picks,
        private readonly array $judges = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('catalog-storefront:parity')
            ->setDescription('Compares the core path and the document path of catalog GraphQL queries')
            ->addArgument(self::ENDPOINT, InputArgument::REQUIRED, 'The GraphQL endpoint, for example https://shop.example/graphql')
            ->addOption(self::CANDIDATE_ENDPOINT, null, InputOption::VALUE_REQUIRED, 'Document-path endpoint to compare with the reference endpoint; defaults to the reference')
            ->addOption(self::REPORT, null, InputOption::VALUE_REQUIRED, 'Write a JSON verdict report to this file (its directory must exist)')
            ->addOption(self::INSECURE, null, InputOption::VALUE_NONE, 'Disable TLS certificate verification for local development endpoints')
            ->addOption(self::QUERIES, null, InputOption::VALUE_REQUIRED, 'Directory of .graphql files', dirname(__DIR__, 4) . '/dev/parity/queries')
            ->addOption(self::DUMP, null, InputOption::VALUE_REQUIRED, 'Directory that keeps both responses of every query')
            ->addOption(self::WARM, null, InputOption::VALUE_REQUIRED, 'Unjudged runs of every query per path before the judged one', '2')
            ->addOption(self::HEADER, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A request header for every query, "Name: value"; a customer token makes it a signed-in gate')
            ->addOption(self::ATTEMPTS, null, InputOption::VALUE_REQUIRED, 'Judged requests of a query before a failing verdict counts', '3')
            ->addOption(self::SOAK, null, InputOption::VALUE_REQUIRED, 'Judged requests to run after the gate, with the context varied per request', '0')
            ->addOption(self::TOKEN, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A customer token the soak takes turns with, next to the guest context')
            ->addOption(self::TOKEN_FILE, null, InputOption::VALUE_REQUIRED, 'A file of customer tokens for the soak, one per line')
            ->addOption(self::PROBE_HEADER, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A header of the soak\'s probe request, "Name: value"')
            ->addOption(self::PROBE_SHARE, null, InputOption::VALUE_REQUIRED, 'Share of the soak\'s requests that send a probe request first, in percent', '25')
            ->addOption(self::MEMORY_SAMPLE, null, InputOption::VALUE_REQUIRED, 'Sample the worker\'s memory every this many soak requests', '0')
            ->addOption(self::MEMORY_SLOPE, null, InputOption::VALUE_REQUIRED, 'Memory the soak may gain per 100 requests over the second half of the run, in KB', '1500')
            ->addOption(self::WORKER_CONTAINER, null, InputOption::VALUE_REQUIRED, 'Container of the worker whose memory is sampled', 'project-backend-frankenphp-1')
            ->addOption(self::WORKER_PROCESS, null, InputOption::VALUE_REQUIRED, 'Command name of the worker process inside that container', 'frankenphp');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->verifyPeer = !$input->getOption(self::INSECURE);
        $key = $this->config->key();
        if ($key === '') {
            $output->writeln('<error>Save Catalog > Catalog > Catalog Storefront Document Store once to generate the storefront key.</error>');

            return Command::FAILURE;
        }
        $endpoint = (string)$input->getArgument(self::ENDPOINT);
        $candidateEndpoint = $input->getOption(self::CANDIDATE_ENDPOINT) ?? $endpoint;
        if (trim((string)$candidateEndpoint) === '') {
            $output->writeln('<error>The candidate endpoint must not be empty.</error>');

            return Command::FAILURE;
        }
        $endpoints = [Mode::CORE => $endpoint, Mode::DOCUMENTS => (string)$candidateEndpoint];
        $files = glob(rtrim((string)$input->getOption(self::QUERIES), '/') . '/*.graphql') ?: [];
        sort($files);
        if (!$files) {
            $output->writeln('<error>No .graphql files found.</error>');

            return Command::FAILURE;
        }
        $warm = max(0, (int)$input->getOption(self::WARM));
        $attempts = max(1, (int)$input->getOption(self::ATTEMPTS));
        $dump = $input->getOption(self::DUMP);
        $shared = [];
        foreach ((array)$input->getOption(self::HEADER) as $header) {
            [$headerName, $value] = array_map('trim', explode(':', $header, 2) + [1 => '']);
            $shared[$headerName] = $value;
        }

        $failed = 0;
        $skipped = 0;
        $results = [];
        $set = [];
        foreach ($files as $file) {
            $name = basename($file, '.graphql');
            $query = (string)file_get_contents($file);
            preg_match_all('/^#\s*@header\s+([\w-]+):\s*(.+?)\s*$/m', $query, $matches, PREG_SET_ORDER);
            $headers = array_combine(array_column($matches, 1), array_column($matches, 2)) + $shared;
            preg_match_all('/^#\s*@requires\s+(!?)([\w-]+)\s*$/m', $query, $required, PREG_SET_ORDER);
            $set[$name] = ['query' => $query, 'headers' => array_combine(array_column($matches, 1), array_column($matches, 2)), 'required' => $required];
            $unmet = array_filter(
                $required,
                static fn(array $requirement) => isset($headers[$requirement[2]]) === ($requirement[1] === '!')
            );
            if ($unmet) {
                $skipped++;
                $output->writeln(sprintf(
                    'SKIP  %s: the run %s',
                    $name,
                    implode(', ', array_map(
                        static fn(array $requirement) => ($requirement[1] === '!' ? 'sends the ' : 'sends no ') . $requirement[2] . ' header',
                        $unmet
                    ))
                ));
                continue;
            }
            try {
                $headers = array_map(fn(string $value) => $this->resolve($value, $endpoint, $key, $shared), $headers);
            } catch (\RuntimeException $e) {
                $skipped++;
                $output->writeln(sprintf('SKIP  %s: %s', $name, $e->getMessage()));
                continue;
            }
            preg_match_all('/^#\s*@requires-field\s+(\w+)\.(\w+)\s*$/m', $query, $fields, PREG_SET_ORDER);
            $missing = array_filter($fields, fn(array $field) => !$this->hasField($endpoint, $key, $headers, $field[1], $field[2]));
            if ($missing) {
                $skipped++;
                $output->writeln(sprintf(
                    'SKIP  %s: the schema has no %s',
                    $name,
                    implode(', ', array_map(static fn(array $field) => $field[1] . '.' . $field[2], $missing))
                ));
                continue;
            }
            try {
                $query = $this->resolve($query, $endpoint, $key, $shared);
            } catch (\RuntimeException $e) {
                $skipped++;
                $output->writeln(sprintf('SKIP  %s: %s', $name, $e->getMessage()));
                continue;
            }
            foreach ([Mode::CORE, Mode::DOCUMENTS] as $mode) {
                for ($run = 0; $run < $warm; $run++) {
                    $this->request($endpoints[$mode], $query, $mode, $key, $headers);
                }
            }
            [$ok, $messages, $attempt] = $this->attempt($endpoints, $query, $name, $key, $headers, $attempts, $dump, $output);
            $output->write($messages);
            $results[] = [
                'name' => $name,
                'queryHash' => hash('sha256', $query),
                'passed' => $ok,
                'attempts' => $attempt,
                'messages' => $messages,
            ];
            $failed += $ok ? 0 : 1;
        }
        $judged = count($files) - $skipped;
        $output->writeln(sprintf("\n%d of %d queries identical, %d skipped", $judged - $failed, $judged, $skipped));

        $soak = null;
        if ((int)$input->getOption(self::SOAK) > 0) {
            $soak = $this->soak($input, $output, $endpoints, $key, $shared, $set, $attempts);
        }

        if ($input->getOption(self::REPORT) !== null) {
            $path = (string)$input->getOption(self::REPORT);
            $report = [
                'schemaVersion' => 1,
                'total' => count($files),
                'passed' => $judged - $failed,
                'failed' => $failed,
                'skipped' => $skipped,
                'results' => $results,
                'soak' => $soak,
            ];
            if (!$this->writeReport($path, $report)) {
                $output->writeln('<error>Could not write the parity report.</error>');

                return Command::FAILURE;
            }
        }

        return $failed > 0 || ($soak !== null && !$soak['passed']) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The soak: the queries the gate's own judges accept in a context are run
     * again for as many requests as `--soak` names, with the context varied per
     * request. The customer takes turns between the guest and every `--token`,
     * which varies the customer group, the cache id and the tax destination;
     * the page size and the sku list of the query step through the values the
     * query itself names; the two paths of a request alternate their order;
     * and a share of the requests sends a probe request with the
     * `--probe-header` headers first. Every request is judged as the gate
     * judges it, so state a memo keeps from the request before shows up as a
     * diff with the request number, the headers and the query.
     *
     * `--memory-sample` samples the resident memory of the worker process
     * every so many requests. The slope over the second half of the run says
     * whether a memo grows per request; the first half is the warm-up, where
     * every thread still fills its memos and builds the schema of a query
     * shape it did not serve yet. The default of `--memory-slope` is the
     * noise of a worker of 25 threads: its resident memory swings about 7 MB
     * around its level between samples, so a slope under 1500 KB per 100
     * requests says nothing over a few thousand requests, while a memo that
     * keeps one document per request adds ten times as much.
     *
     * @param array<string, string> $endpoints
     * @param array<string, string> $shared
     * @param array<string, array{query: string, headers: array<string, string>, required: array}> $set
     * @return array<string, mixed>
     */
    private function soak(
        InputInterface $input,
        OutputInterface $output,
        array $endpoints,
        string $key,
        array $shared,
        array $set,
        int $attempts,
    ): array {
        $requests = (int)$input->getOption(self::SOAK);
        $tokens = (array)$input->getOption(self::TOKEN);
        $tokenFile = (string)$input->getOption(self::TOKEN_FILE);
        if ($tokenFile !== '') {
            $tokens = array_merge($tokens, array_values(array_filter(array_map('trim', (array)file($tokenFile)))));
        }
        $contexts = ['guest' => []];
        foreach ($tokens as $position => $token) {
            $contexts['token ' . ($position + 1)] = ['Authorization' => 'Bearer ' . $token];
        }
        $probe = [];
        foreach ((array)$input->getOption(self::PROBE_HEADER) as $header) {
            [$headerName, $value] = array_map('trim', explode(':', $header, 2) + [1 => '']);
            $probe[$headerName] = $value;
        }
        $probeShare = $probe === [] ? 0 : max(0, min(100, (int)$input->getOption(self::PROBE_SHARE)));
        $sampleEvery = max(0, (int)$input->getOption(self::MEMORY_SAMPLE));
        $limit = (float)$input->getOption(self::MEMORY_SLOPE);
        $container = (string)$input->getOption(self::WORKER_CONTAINER);
        $process = (string)$input->getOption(self::WORKER_PROCESS);

        // A query the judges already refuse in a context cannot show a memo: it is left out with its verdict.
        $work = [];
        foreach ($contexts as $label => $context) {
            foreach ($set as $name => $entry) {
                $headers = $entry['headers'] + $context + $shared;
                $unmet = array_filter(
                    $entry['required'],
                    static fn(array $requirement) => isset($headers[$requirement[2]]) === ($requirement[1] === '!')
                );
                if ($unmet) {
                    continue;
                }
                try {
                    $headers = array_map(fn(string $value) => $this->resolve($value, $endpoints[Mode::CORE], $key, $shared), $headers);
                    $query = $this->resolve($entry['query'], $endpoints[Mode::CORE], $key, $shared);
                } catch (\RuntimeException) {
                    continue;
                }
                [$ok] = $this->attempt($endpoints, $query, $name, $key, $headers, $attempts, null, $output);
                if ($ok) {
                    $work[$label][] = ['name' => $name, 'query' => $query, 'headers' => $headers];
                }
            }
            $output->writeln(sprintf('SOAK  %s: %d of %d queries', $label, count($work[$label] ?? []), count($set)));
        }
        $work = array_filter($work);
        if (!$work) {
            $output->writeln('<error>The soak has no query the judges accept.</error>');

            return ['requests' => 0, 'passed' => false, 'diffs' => [], 'samples' => [], 'slope' => null];
        }

        $labels = array_keys($work);
        $positions = array_fill_keys($labels, 0);
        $samples = [];
        $diffs = [];
        $probes = 0;
        $probeErrors = 0;
        if ($sampleEvery > 0) {
            $samples[] = ['requests' => 0] + ($this->workerMemory($container, $process) ?? ['rss' => 0, 'hwm' => 0]);
        }
        for ($request = 1; $request <= $requests; $request++) {
            $label = $labels[($request - 1) % count($labels)];
            $item = $work[$label][$positions[$label]++ % count($work[$label])];
            $query = self::vary($item['query'], $request);
            if ($probeShare > 0 && ($request * $probeShare) % 100 < $probeShare) {
                $answer = $this->request($endpoints[Mode::DOCUMENTS], $query, Mode::DOCUMENTS, $key, $probe + $item['headers']);
                $probes++;
                $probeErrors += isset($answer['errors']) ? 1 : 0;
            }
            [$ok, $messages] = $this->attempt(
                $endpoints,
                $query,
                $item['name'],
                $key,
                $item['headers'],
                $attempts,
                null,
                $output,
                $request % 2 === 0,
            );
            if (!$ok) {
                $diffs[] = [
                    'request' => $request,
                    'name' => $item['name'],
                    'context' => $label,
                    'headers' => array_keys($item['headers']),
                    'query' => $query,
                    'messages' => $messages,
                ];
                $output->writeln(sprintf('SOAK  request %d, %s, %s', $request, $label, $item['name']));
                $output->write($messages);
            }
            if ($sampleEvery > 0 && $request % $sampleEvery === 0) {
                $samples[] = ['requests' => $request] + ($this->workerMemory($container, $process) ?? ['rss' => 0, 'hwm' => 0]);
            }
        }

        $slope = self::slope($samples);
        $output->writeln(sprintf(
            "\nSoak: %d requests over %d contexts, %d probe requests of which %d answered an error, %d diffs",
            $requests,
            count($labels),
            $probes,
            $probeErrors,
            count($diffs)
        ));
        if ($samples) {
            $first = reset($samples);
            $last = end($samples);
            $output->writeln(sprintf(
                'Memory: %d KB at %d requests, %d KB at %d requests, peak %d KB',
                $first['rss'],
                $first['requests'],
                $last['rss'],
                $last['requests'],
                $last['hwm']
            ));
            $output->writeln($slope === null
                ? 'Memory: too few samples for a slope'
                : sprintf('Memory: %.1f KB per 100 requests over the second half, limit %.1f', $slope, $limit));
            $output->writeln(sprintf('%12s %12s %12s', 'requests', 'RSS KB', 'peak KB'));
            foreach ($samples as $sample) {
                $output->writeln(sprintf('%12d %12d %12d', $sample['requests'], $sample['rss'], $sample['hwm']));
            }
        }
        $grows = $slope !== null && $slope > $limit;
        if ($grows) {
            $output->writeln(sprintf('<error>The worker gains %.1f KB per 100 requests, above %.1f.</error>', $slope, $limit));
        }

        return [
            'requests' => $requests,
            'contexts' => $labels,
            'queries' => array_map(static fn(array $items) => array_column($items, 'name'), $work),
            'probeHeaders' => array_keys($probe),
            'probeShare' => $probeShare,
            'probes' => $probes,
            'probeErrors' => $probeErrors,
            'sampleEvery' => $sampleEvery,
            'slopeLimit' => $limit,
            'slope' => $slope,
            'samples' => $samples,
            'diffs' => $diffs,
            'passed' => $diffs === [] && !$grows,
        ];
    }

    /**
     * The query of soak request `$index`. A page size, named as an argument or
     * as the default of a query variable, steps through the whole, the half,
     * the quarter and one less than the size the query itself names, and a sku
     * list rotates. Both paths get the same text, so a variation makes no
     * difference of its own: what it changes is the context a memo could keep
     * from the request before. A variation keeps the set the query asks for,
     * so a catalog that holds one product of a list still answers.
     */
    public static function vary(string $query, int $index): string
    {
        $query = preg_replace_callback(
            '/(\bpageSize:\s*(?:Int!?\s*=\s*)?)(\d+)/',
            static function (array $match) use ($index): string {
                $size = (int)$match[2];
                $sizes = [$size, (int)ceil($size / 2), (int)ceil($size / 4), $size - 1];

                return $match[1] . max(1, $sizes[$index % 4]);
            },
            $query
        );

        return preg_replace_callback(
            '/(\bsku:\s*\{\s*in:\s*\[)([^\]]+)(\])/',
            static function (array $match) use ($index): string {
                $values = array_map('trim', explode(',', $match[2]));
                $offset = $index % count($values);

                return $match[1]
                    . implode(', ', array_merge(array_slice($values, $offset), array_slice($values, 0, $offset)))
                    . $match[3];
            },
            $query
        );
    }

    /**
     * The least squares slope of the samples of the second half of the run, in
     * KB per 100 requests, or null where the half holds fewer than two samples
     * or all of them name one request count.
     *
     * @param list<array{requests: int, rss: int}> $samples
     */
    public static function slope(array $samples): ?float
    {
        if (count($samples) < 2) {
            return null;
        }
        $end = end($samples)['requests'];
        $half = array_values(array_filter($samples, static fn(array $sample) => $sample['requests'] * 2 >= $end));
        if (count($half) < 2) {
            return null;
        }
        $meanRequests = array_sum(array_column($half, 'requests')) / count($half);
        $meanRss = array_sum(array_column($half, 'rss')) / count($half);
        $covariance = 0.0;
        $variance = 0.0;
        foreach ($half as $sample) {
            $distance = $sample['requests'] - $meanRequests;
            $covariance += $distance * ($sample['rss'] - $meanRss);
            $variance += $distance ** 2;
        }

        return $variance > 0.0 ? $covariance / $variance * 100 : null;
    }

    /**
     * The resident and the peak memory of the worker process of a container,
     * in KB. The PHP threads are threads of that process, so one process holds
     * the state of all of them; the shell that reads the table names the
     * process too, and the largest reader wins.
     *
     * @return array{rss: int, hwm: int}|null
     */
    protected function workerMemory(string $container, string $process): ?array
    {
        if (!preg_match('/^[\w.-]+$/D', $process)) {
            return null;
        }
        $script = 'for d in /proc/[0-9]*; do case "$(tr "\0" " " < $d/cmdline 2>/dev/null)" in '
            . '*' . $process . '*) grep -E "^Vm(RSS|HWM):" $d/status 2>/dev/null;; esac; done';
        exec('docker exec ' . escapeshellarg($container) . ' sh -c ' . escapeshellarg($script) . ' 2>/dev/null', $lines);

        $worker = null;
        $peak = 0;
        foreach ($lines as $line) {
            if (preg_match('/^VmHWM:\s*(\d+)/', $line, $match)) {
                $peak = (int)$match[1];
            }
            if (preg_match('/^VmRSS:\s*(\d+)/', $line, $match) && (int)$match[1] > ($worker['rss'] ?? 0)) {
                $worker = ['rss' => (int)$match[1], 'hwm' => $peak];
            }
        }

        return $worker;
    }

    /**
     * One judged comparison, requested again up to `$attempts` times before a
     * failing verdict counts. `$documentsFirst` puts the document path first,
     * so a soak run sends the two paths of a request in both orders.
     *
     * @param array<string, string> $endpoints
     * @param array<string, string> $headers
     * @return array{0: bool, 1: string, 2: int} the verdict, its messages and the attempts it took
     */
    private function attempt(
        array $endpoints,
        string $query,
        string $name,
        string $key,
        array $headers,
        int $attempts,
        ?string $dump,
        OutputInterface $output,
        bool $documentsFirst = false,
    ): array {
        $order = $documentsFirst ? [Mode::DOCUMENTS, Mode::CORE] : [Mode::CORE, Mode::DOCUMENTS];
        for ($attempt = 1; ; $attempt++) {
            $responses = [];
            foreach ($order as $mode) {
                $responses[$mode] = $this->request($endpoints[$mode], $query, $mode, $key, $headers);
            }
            if ($dump) {
                @mkdir($dump, 0777, true);
                foreach ($responses as $mode => $response) {
                    file_put_contents("$dump/$name.$mode.json", json_encode($response, JSON_PRETTY_PRINT));
                }
            }
            $verdict = new BufferedOutput($output->getVerbosity(), $output->isDecorated());
            $ok = $this->judge($verdict, $name, $responses[Mode::CORE], $responses[Mode::DOCUMENTS]);
            if ($ok || $attempt >= $attempts) {
                return [$ok, $verdict->fetch(), $attempt];
            }
        }
    }

    private ?string $cartId = null;

    /**
     * The query with its placeholders filled from the catalog. The guest cart
     * of `{{cart_id}}` is created on the reference endpoint on its first use.
     *
     * @param array<string, string> $headers
     * @throws \RuntimeException where the catalog cannot fill a placeholder
     */
    private function resolve(string $query, string $endpoint, string $key, array $headers): string
    {
        return preg_replace_callback(
            '/\{\{(\w+)(?::([^}]*))?\}\}/',
            function (array $match) use ($endpoint, $key, $headers): string {
                [, $name, $argument] = $match + [2 => ''];
                $kind = $argument === '' ? 'any' : $argument;
                $value = match ($name) {
                    'sku' => $this->picks->skus($kind, 1)[0] ?? null,
                    'skus' => $this->skuList($argument),
                    'url_key' => $this->picks->urlKey($kind),
                    'category_id' => $this->picks->categoryId($kind),
                    'category_url_path' => $this->picks->categoryUrlPath($kind),
                    'option_uid' => str_contains($argument, ':') ? $this->namedOptionUid($argument) : $this->picks->optionUid($kind),
                    'search_term' => $this->picks->searchTerm(),
                    'currency' => $this->picks->currency(),
                    'cart_id' => $this->cartId ??= $this->cartId($endpoint, $key, $headers),
                    default => throw new \RuntimeException(sprintf('unknown placeholder {{%s}}', $name)),
                };
                if ($value === null || $value === '') {
                    if ($name === 'currency') {
                        throw new \RuntimeException('the store has no alternate currency with an exchange rate');
                    }
                    throw new \RuntimeException(sprintf('the catalog has no %s for {{%s}}', str_replace(':', ' ', $kind), $match[1] . ($argument === '' ? '' : ':' . $argument)));
                }

                return (string)$value;
            },
            $query
        );
    }

    /**
     * `{{skus:<kind>:<count>}}`: the skus as a GraphQL list body, `"a", "b"`.
     */
    private function skuList(string $argument): ?string
    {
        $count = 1;
        $kind = $argument === '' ? 'any' : $argument;
        if (preg_match('/^(.*):(\d+)$/', $kind, $match)) {
            $kind = $match[1];
            $count = max(1, (int)$match[2]);
        }
        $skus = $this->picks->skus($kind, $count);

        return $skus ? implode(', ', array_map(static fn(string $sku) => json_encode($sku), $skus)) : null;
    }

    private function namedOptionUid(string $argument): string
    {
        [$code, $label] = explode(':', $argument, 2);
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);

        return $this->uidEncoder->encode('configurable/' . $attribute->getId() . '/' . $attribute->getSource()->getOptionId($label));
    }

    /**
     * A guest cart on the reference endpoint with two of the first simple product.
     *
     * @param array<string, string> $headers
     * @throws \RuntimeException
     */
    private function cartId(string $endpoint, string $key, array $headers): string
    {
        $sku = $this->picks->skus('simple', 1)[0]
            ?? $this->picks->skus('simple:child', 1)[0]
            ?? throw new \RuntimeException('the catalog has no simple product for {{cart_id}}');
        $created = $this->request($endpoint, 'mutation { createEmptyCart }', Mode::CORE, $key, $headers);
        $cartId = $created['data']['createEmptyCart'] ?? null;
        if (!is_string($cartId) || $cartId === '') {
            throw new \RuntimeException('the endpoint created no guest cart for {{cart_id}}: ' . json_encode($created['errors'][0]['message'] ?? $created));
        }
        $added = $this->request($endpoint, sprintf(
            'mutation { addProductsToCart(cartId: %s, cartItems: [{ sku: %s, quantity: 2 }]) { user_errors { message } } }',
            json_encode($cartId),
            json_encode($sku)
        ), Mode::CORE, $key, $headers);
        $error = $added['errors'][0]['message'] ?? $added['data']['addProductsToCart']['user_errors'][0]['message'] ?? null;
        if ($error !== null) {
            throw new \RuntimeException(sprintf('the endpoint refused %s in the guest cart for {{cart_id}}: %s', $sku, $error));
        }

        return $cartId;
    }

    /** @var array<string, string[]> the field names per type of the reference endpoint */
    private array $schemaFields = [];

    /**
     * @param array<string, string> $headers
     */
    private function hasField(string $endpoint, string $key, array $headers, string $type, string $field): bool
    {
        if (!isset($this->schemaFields[$type])) {
            $response = $this->request($endpoint, sprintf('{ __type(name: %s) { fields { name } } }', json_encode($type)), Mode::CORE, $key, $headers);
            $this->schemaFields[$type] = array_column((array)($response['data']['__type']['fields'] ?? []), 'name');
        }

        return in_array($field, $this->schemaFields[$type], true);
    }

    private function judge(OutputInterface $output, string $name, array $core, array $documents): bool
    {
        // An error on either path is never parity, even when both paths fail alike.
        foreach ([Mode::CORE => $core, Mode::DOCUMENTS => $documents] as $mode => $response) {
            if (isset($response['errors'])) {
                $output->writeln(sprintf('ERROR %s (%s path): %s', $name, $mode, substr(json_encode($response['errors'][0]['message'] ?? $response['errors']), 0, 200)));

                return false;
            }
            if (!isset($response['data']) || !is_array($response['data']) || $response['data'] === []) {
                $output->writeln(sprintf('ERROR %s (%s path): no GraphQL data returned', $name, $mode));

                return false;
            }
            // A query that returns no product compares nothing: a hidden product passes vacuously.
            if (array_key_exists('products', $response['data'])
                && (!is_array($response['data']['products'])
                    || (array_key_exists('items', $response['data']['products'])
                        && (!is_array($response['data']['products']['items'])
                            || $response['data']['products']['items'] === [])))
            ) {
                $output->writeln(sprintf('EMPTY %s (%s path): no products returned', $name, $mode));

                return false;
            }
        }
        $report = $documents['extensions']['catalogStorefront'] ?? [];
        if (($report['mode'] ?? null) !== Mode::DOCUMENTS) {
            $output->writeln(sprintf('ERROR %s: the endpoint did not honour the %s header', $name, Mode::HEADER));

            return false;
        }
        foreach ((array)($report['fallbacks'] ?? []) as $fallback) {
            $output->writeln(sprintf('FALLBACK %s: %s', $name, $fallback));
        }
        $ok = empty($report['fallbacks']);
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
        foreach ($this->judges as $judge) {
            $ok = $judge->judge($name, $core, $documents, $output) && $ok;
        }
        if ($ok) {
            $output->writeln('PASS  ' . $name);
        }

        return $ok;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function request(string $endpoint, string $query, string $mode, string $key, array $headers): array
    {
        $headers = ['Content-Type' => 'application/json', Mode::HEADER => $mode, StorefrontKey::HEADER => $key] + $headers;
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode('', array_map(static fn($name, $value) => "$name: $value\r\n", array_keys($headers), $headers)),
            'content' => json_encode(['query' => $query]),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 120,
        ], 'ssl' => ['verify_peer' => $this->verifyPeer, 'verify_peer_name' => $this->verifyPeer]]);
        $body = @file_get_contents($endpoint, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $match)) {
                $status = (int)$match[1];
            }
        }

        return $this->decodeResponse($body, $status);
    }

    private function decodeResponse(string|false $body, int $status): array
    {
        if ($body === false || $status < 200 || $status >= 300) {
            return ['errors' => [['message' => sprintf('GraphQL transport failed (HTTP %d)', $status)]]];
        }

        $response = json_decode((string)$body, true);

        return is_array($response) ? $response : ['errors' => [['message' => 'invalid GraphQL response']]];
    }

    /** @param array<string, mixed> $report */
    private function writeReport(string $path, array $report): bool
    {
        if ($path === '' || !is_dir(dirname($path)) || !is_writable(dirname($path))) {
            return false;
        }
        $temporary = @tempnam(dirname($path), '.parity-');
        if ($temporary === false) {
            return false;
        }
        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";

            return file_put_contents($temporary, $json) !== false && @rename($temporary, $path);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Orders that core leaves undefined are not document differences: aggregation
     * options tie-break in the search engine, and configurable_options come from a
     * collection without ORDER BY, and so do an option's values and a category's
     * children of equal position, and reviews of one created_at come in database
     * order. All are sorted so the diff sees the set, not the order. Zero-count aggregation options are dropped: core's option provider joins
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
            // Core lists an option's values and a category's children among equal positions in database order.
            if ($key === 'values' && is_array($child) && (isset($child[0]['value_index']) || isset($child[0]['uid']))) {
                usort($child, static fn($a, $b) => [$a['value_index'] ?? 0, $a['uid'] ?? ''] <=> [$b['value_index'] ?? 0, $b['uid'] ?? '']);
                $node[$key] = $child;
            }
            if ($key === 'reviews' && is_array($child['items'] ?? null)) {
                usort($child['items'], static fn($a, $b) => [$b['created_at'] ?? '', $a['nickname'] ?? '', $a['summary'] ?? '']
                    <=> [$a['created_at'] ?? '', $b['nickname'] ?? '', $b['summary'] ?? '']);
                $node[$key] = $child;
            }
            // Core lists a tax adjustment of a float remainder (1e-14) where the tax is zero.
            if ($key === 'adjustments' && is_array($child)) {
                $node[$key] = array_values(array_filter($child, static fn($adjustment) => abs((float)($adjustment['amount']['value'] ?? 0)) >= 0.000001));
            }
            if ($key === 'children' && is_array($child) && isset($child[0]['uid'])) {
                usort($child, static fn($a, $b) => [(int)($a['position'] ?? 0), $a['uid'] ?? ''] <=> [(int)($b['position'] ?? 0), $b['uid'] ?? '']);
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
