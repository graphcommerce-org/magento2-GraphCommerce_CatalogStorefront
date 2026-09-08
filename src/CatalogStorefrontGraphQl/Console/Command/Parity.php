<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Console\Command;

use GraphCommerce\CatalogStorefrontGraphQlApi\Parity\JudgeInterface;
use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
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
 * The parity gate: runs every query of a directory against one GraphQL
 * endpoint on the core path and on the document path, picked per request
 * with the X-Catalog-Storefront header under the storefront key, and diffs
 * the responses. The document path's fallbacks come back in the response
 * extensions and are printed. Every query runs unjudged first so the gate
 * sees the steady state, and a query that fails is requested again up to
 * `--attempts` times before its verdict counts: a worker thread that has not
 * served the shape yet answers from a cold state once. A query file sends extra request headers through
 * comment lines of the form `# @header Content-Currency: EUR`, and names a
 * configurable option value as `{{option_uid:<attribute code>:<admin label>}}`,
 * resolved to the installation's ids; `--header`
 * sends one with every query, a customer token for a signed-in gate. Other
 * modules add verdicts through di.xml `judges`.
 */
class Parity extends Command
{
    private const ENDPOINT = 'endpoint';
    private const QUERIES = 'queries';
    private const DUMP = 'dump';
    private const WARM = 'warm';
    private const HEADER = 'header';
    private const ATTEMPTS = 'attempts';

    /**
     * @param JudgeInterface[] $judges
     */
    public function __construct(
        private readonly Config $config,
        private readonly EavConfig $eavConfig,
        private readonly Uid $uidEncoder,
        private readonly array $judges = [],
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
            ->addOption(self::WARM, null, InputOption::VALUE_REQUIRED, 'Unjudged runs of every query per path before the judged one', '2')
            ->addOption(self::HEADER, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A request header for every query, "Name: value"; a customer token makes it a signed-in gate')
            ->addOption(self::ATTEMPTS, null, InputOption::VALUE_REQUIRED, 'Judged requests of a query before a failing verdict counts', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $this->config->key();
        if ($key === '') {
            $output->writeln('<error>Save Catalog > Catalog > Catalog Storefront Document Store once to generate the storefront key.</error>');

            return Command::FAILURE;
        }
        $endpoint = (string)$input->getArgument(self::ENDPOINT);
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
        foreach ($files as $file) {
            $name = basename($file, '.graphql');
            $query = preg_replace_callback(
                '/\{\{option_uid:([\w-]+):([^}]+)\}\}/',
                function (array $match): string {
                    $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $match[1]);

                    return $this->uidEncoder->encode(
                        'configurable/' . $attribute->getId() . '/' . $attribute->getSource()->getOptionId($match[2])
                    );
                },
                (string)file_get_contents($file)
            );
            preg_match_all('/^#\s*@header\s+([\w-]+):\s*(.+?)\s*$/m', $query, $matches, PREG_SET_ORDER);
            $headers = array_combine(array_column($matches, 1), array_column($matches, 2)) + $shared;
            foreach ([Mode::CORE, Mode::DOCUMENTS] as $mode) {
                for ($run = 0; $run < $warm; $run++) {
                    $this->request($endpoint, $query, $mode, $key, $headers);
                }
            }
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $responses = [];
                foreach ([Mode::CORE, Mode::DOCUMENTS] as $mode) {
                    $responses[$mode] = $this->request($endpoint, $query, $mode, $key, $headers);
                }
                if ($dump) {
                    @mkdir($dump, 0777, true);
                    foreach ($responses as $mode => $response) {
                        file_put_contents("$dump/$name.$mode.json", json_encode($response, JSON_PRETTY_PRINT));
                    }
                }
                $verdict = new BufferedOutput($output->getVerbosity(), $output->isDecorated());
                $ok = $this->judge($verdict, $name, $responses[Mode::CORE], $responses[Mode::DOCUMENTS]);
                if ($ok || $attempt === $attempts) {
                    $output->write($verdict->fetch());
                    $failed += $ok ? 0 : 1;
                    break;
                }
            }
        }
        $output->writeln(sprintf("\n%d of %d queries identical", count($files) - $failed, count($files)));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function judge(OutputInterface $output, string $name, array $core, array $documents): bool
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
        if (($report['mode'] ?? null) !== Mode::DOCUMENTS) {
            $output->writeln(sprintf('ERROR %s: the endpoint did not honour the %s header', $name, Mode::HEADER));

            return false;
        }
        foreach ((array)($report['fallbacks'] ?? []) as $fallback) {
            $output->writeln(sprintf('FALLBACK %s: %s', $name, $fallback));
        }
        $ok = true;
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
    private function request(string $endpoint, string $query, string $mode, string $key, array $headers): array
    {
        $headers = ['Content-Type' => 'application/json', Mode::HEADER => $mode, StorefrontKey::HEADER => $key] + $headers;
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
