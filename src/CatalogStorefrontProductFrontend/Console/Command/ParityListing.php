<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Console\Command;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Compares the core path and the document path of rendered listing pages.
 *
 * The GraphQL gate next door judges a JSON tree; this one judges HTML, so it
 * compares lines after the per-render tokens are normalised away. Both paths
 * are fetched in one run: the storefront key lets a request name its own path,
 * so nothing has to be reconfigured or restarted between them.
 */
class ParityListing extends Command
{
    private const BASE = 'base-url';
    private const PAGES = 'pages';
    private const DUMP = 'dump';
    private const WARM = 'warm';
    private const HOST = 'host';
    private const HEADER = 'header';

    /**
     * What changes on every render and says nothing about the page: the uniqid suffix a
     * theme gives its element ids, and the timestamp Magento stamps on each private
     * content section.
     */
    private const PER_RENDER = [
        '/_[0-9a-f]{13}\b/' => '_UID',
        '/"data_id":\d+/' => '"data_id":TIME',
    ];

    /**
     * @param array<string, string> $perRender more of the same, pattern to replacement, from
     *   the di.xml of a theme module
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $perRender = [],
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('catalog-storefront:parity:listing')
            ->setDescription('Compares the core path and the document path of rendered product listing pages')
            ->addArgument(self::BASE, InputArgument::REQUIRED, 'Base URL, for example https://shop.example')
            ->addOption(self::PAGES, null, InputOption::VALUE_REQUIRED, 'File of listing paths, one per line', dirname(__DIR__, 4) . '/dev/parity/listing-pages.txt')
            ->addOption(self::DUMP, null, InputOption::VALUE_REQUIRED, 'Directory that keeps both renders of every page')
            ->addOption(self::WARM, null, InputOption::VALUE_REQUIRED, 'Unjudged renders of every page per path before the judged one', '1')
            ->addOption(self::HOST, null, InputOption::VALUE_REQUIRED, 'Host header, when the base URL is not the store domain')
            ->addOption(self::HEADER, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A request header for every page, "Name: value"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $this->config->key();
        if ($key === '') {
            $output->writeln('<error>Save Catalog > Catalog > Catalog Storefront Document Store once to generate the storefront key.</error>');

            return Command::FAILURE;
        }

        $file = (string)$input->getOption(self::PAGES);
        if (!is_readable($file)) {
            $output->writeln(sprintf('<error>No page list at %s.</error>', $file));

            return Command::FAILURE;
        }
        $pages = [];
        foreach (preg_split('/\R/', (string)file_get_contents($file)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $pages[] = $line;
            }
        }
        if (!$pages) {
            $output->writeln(sprintf('<error>%s lists no pages.</error>', $file));

            return Command::FAILURE;
        }

        $base = rtrim((string)$input->getArgument(self::BASE), '/');
        $warm = max(0, (int)$input->getOption(self::WARM));
        $dump = $input->getOption(self::DUMP);

        $shared = [];
        $host = (string)$input->getOption(self::HOST);
        if ($host !== '') {
            $shared['Host'] = $host;
        }
        foreach ((array)$input->getOption(self::HEADER) as $header) {
            [$headerName, $value] = array_map('trim', explode(':', $header, 2) + [1 => '']);
            $shared[$headerName] = $value;
        }

        $failed = 0;
        foreach ($pages as $page) {
            $url = $base . '/' . ltrim($page, '/');
            foreach ([Mode::CORE, Mode::DOCUMENTS] as $mode) {
                for ($run = 0; $run < $warm; $run++) {
                    $this->request($url, $mode, $key, $shared);
                }
            }

            $renders = [];
            foreach ([Mode::CORE, Mode::DOCUMENTS] as $mode) {
                $renders[$mode] = $this->request($url, $mode, $key, $shared);
            }
            if ($dump) {
                @mkdir($dump, 0777, true);
                $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($page, '/')) ?: 'index';
                foreach ($renders as $mode => $render) {
                    file_put_contents("$dump/$name.$mode.html", $render['body']);
                }
            }

            $verdict = new BufferedOutput($output->getVerbosity(), $output->isDecorated());
            $failed += $this->judge($verdict, $page, $renders[Mode::CORE], $renders[Mode::DOCUMENTS]) ? 0 : 1;
            $output->write($verdict->fetch());
        }

        $output->writeln(sprintf("\n%d of %d pages identical", count($pages) - $failed, count($pages)));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param array{status: int, body: string, url: string, location: string} $core
     * @param array{status: int, body: string, url: string, location: string} $documents
     */
    private function judge(OutputInterface $output, string $page, array $core, array $documents): bool
    {
        // A page that did not render is never parity, even when both paths fail alike.
        foreach ([Mode::CORE => $core, Mode::DOCUMENTS => $documents] as $mode => $render) {
            if ($render['status'] === 0) {
                // No response at all, so nothing about the page was tested. From inside the app
                // container the store domain does not resolve — reach it as http://localhost with
                // --host and, behind a TLS proxy, --header "X-Forwarded-Proto: https".
                $output->writeln(sprintf('<error>%s: %s got no response from %s</error>', $page, $mode, $render['url']));

                return false;
            }
            if ($render['status'] !== 200) {
                $output->writeln(sprintf(
                    '<error>%s: %s returned HTTP %d</error>%s',
                    $page,
                    $mode,
                    $render['status'],
                    $render['location'] === '' ? '' : ' -> ' . $render['location']
                ));

                return false;
            }
            if (trim($render['body']) === '') {
                $output->writeln(sprintf('<error>%s: %s returned an empty body</error>', $page, $mode));

                return false;
            }
        }

        $a = $this->normalize($core['body']);
        $b = $this->normalize($documents['body']);
        if ($a === $b) {
            $output->writeln(sprintf('<info>%s: identical</info> (%d bytes)', $page, strlen($documents['body'])));

            return true;
        }

        $differences = $this->diff($a, $b);
        $output->writeln(sprintf(
            '<error>%s: %d differing lines</error> (core %d bytes, documents %d bytes)',
            $page,
            count($differences),
            strlen($core['body']),
            strlen($documents['body'])
        ));
        foreach (array_slice($differences, 0, 20) as $difference) {
            $output->writeln('    ' . $difference);
        }
        if (count($differences) > 20) {
            $output->writeln(sprintf('    ... %d more', count($differences) - 20));
        }

        return false;
    }

    /**
     * @return string[] the lines, with what differs per render replaced
     */
    private function normalize(string $body): array
    {
        // The form key is minted per render and appears in several encodings — plain in the
        // markup, backslash-escaped inside embedded JSON, and again in the private content blob.
        // Replacing the value itself catches every one of them; matching each encoding did not.
        if (preg_match('/name="form_key"[^>]*value="([^"]+)"/', $body, $matches) === 1) {
            $body = str_replace($matches[1], 'FORMKEY', $body);
        }
        $perRender = self::PER_RENDER + $this->perRender;
        $body = preg_replace(array_keys($perRender), array_values($perRender), $body) ?? $body;

        return preg_split('/\R/', $body) ?: [];
    }

    /**
     * The lines of one side that are not in the other, in either direction.
     *
     * Deliberately a set difference rather than a sequence diff: a page that
     * moves a block reports the block once, not every line after it.
     *
     * @param string[] $a
     * @param string[] $b
     * @return string[]
     */
    private function diff(array $a, array $b): array
    {
        $lines = [];
        foreach (array_diff_assoc($a, $b) as $index => $line) {
            $lines[] = sprintf('core %d: %s', $index + 1, $this->clip($line));
        }
        foreach (array_diff_assoc($b, $a) as $index => $line) {
            $lines[] = sprintf('docs %d: %s', $index + 1, $this->clip($line));
        }

        return $lines;
    }

    private function clip(string $line): string
    {
        $line = trim($line);

        return strlen($line) > 160 ? substr($line, 0, 160) . '…' : $line;
    }

    /**
     * @return array{status: int, body: string, url: string, location: string}
     */
    private function request(string $url, string $mode, string $key, array $shared): array
    {
        $headers = [Mode::HEADER => $mode, StorefrontKey::HEADER => $key] + $shared;
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode('', array_map(
                static fn($name, $value) => "$name: $value\r\n",
                array_keys($headers),
                $headers
            )),
            'ignore_errors' => true,
            'timeout' => 120,
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $status = (int)$matches[1];
                $location = '';
            }
            if (preg_match('/^Location:\s*(.+?)\s*$/i', $line, $matches)) {
                $location = $matches[1];
            }
        }

        return ['status' => $status, 'body' => (string)$body, 'url' => $url, 'location' => $location];
    }
}
