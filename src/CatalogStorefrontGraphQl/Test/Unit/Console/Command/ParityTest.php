<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Console\Command;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefrontGraphQl\Console\Command\Parity;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\GraphQl\Query\Uid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ParityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/catalog-parity-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory . '/01-product.graphql', '{ products { items { sku } } }');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testCandidateEndpointIsUsedForWarmupAndJudgedRequests(): void
    {
        $command = $this->command();
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute($this->input() + [
            '--candidate-endpoint' => 'https://candidate.example/graphql',
            '--warm' => '1',
            '--report' => $this->directory . '/report.json',
            '--header' => ['Authorization: Bearer test-secret'],
        ]));
        self::assertSame([
            ['https://reference.example/graphql', Mode::CORE],
            ['https://candidate.example/graphql', Mode::DOCUMENTS],
            ['https://reference.example/graphql', Mode::CORE],
            ['https://candidate.example/graphql', Mode::DOCUMENTS],
        ], $command->requests);
        $json = (string)file_get_contents($this->directory . '/report.json');
        $report = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $report['schemaVersion']);
        self::assertSame([1, 1, 0], [$report['total'], $report['passed'], $report['failed']]);
        self::assertSame('01-product', $report['results'][0]['name']);
        self::assertTrue($report['results'][0]['passed']);
        self::assertSame(1, $report['results'][0]['attempts']);
        self::assertSame(hash('sha256', '{ products { items { sku } } }'), $report['results'][0]['queryHash']);
        self::assertStringNotContainsString('test-secret', $json);
        self::assertStringNotContainsString('diagnostic-key', $json);
        self::assertSame([], glob($this->directory . '/.parity-*'));
    }

    public function testDefaultStillComparesBothPathsOnOneEndpoint(): void
    {
        $command = $this->command();
        self::assertSame(0, (new CommandTester($command))->execute($this->input() + ['--warm' => '0']));
        self::assertSame([
            ['https://reference.example/graphql', Mode::CORE],
            ['https://reference.example/graphql', Mode::DOCUMENTS],
        ], $command->requests);
    }

    #[DataProvider('invalidResponses')]
    public function testIncompleteOrFailingResponsesCannotPass(array $candidate): void
    {
        $command = $this->command();
        $command->candidate = $candidate;
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute($this->input() + [
            '--warm' => '0', '--attempts' => '1', '--report' => $this->directory . '/report.json',
        ]));
        $report = json_decode((string)file_get_contents($this->directory . '/report.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $report['failed']);
        self::assertFalse($report['results'][0]['passed']);
    }

    public static function invalidResponses(): iterable
    {
        yield 'missing data' => [['extensions' => ['catalogStorefront' => ['mode' => Mode::DOCUMENTS]]]];
        yield 'null data' => [['data' => null, 'extensions' => ['catalogStorefront' => ['mode' => Mode::DOCUMENTS]]]];
        yield 'missing report' => [['data' => ['products' => ['items' => [['sku' => 'test']]]]]];
        yield 'wrong mode' => [['data' => ['products' => ['items' => [['sku' => 'test']]]], 'extensions' => ['catalogStorefront' => ['mode' => Mode::CORE]]]];
        yield 'empty products' => [['data' => ['products' => ['items' => []]], 'extensions' => ['catalogStorefront' => ['mode' => Mode::DOCUMENTS]]]];
        yield 'changed data' => [['data' => ['products' => ['items' => [['sku' => 'changed']]]], 'extensions' => ['catalogStorefront' => ['mode' => Mode::DOCUMENTS]]]];
        yield 'partial GraphQL error' => [['data' => ['products' => ['items' => [['sku' => 'test']]]], 'errors' => [['message' => 'partial failure']]]];
    }

    public function testBothMissingDataCannotCompareVacuously(): void
    {
        $command = $this->command();
        $command->core = [];
        $command->candidate = ['extensions' => ['catalogStorefront' => ['mode' => Mode::DOCUMENTS]]];
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute($this->input() + ['--warm' => '0', '--attempts' => '1']));
        self::assertStringContainsString('no GraphQL data returned', $tester->getDisplay());
    }

    public function testReportFailureMakesTheCommandFail(): void
    {
        $tester = new CommandTester($this->command());
        self::assertSame(1, $tester->execute($this->input() + [
            '--warm' => '0', '--report' => $this->directory . '/missing/report.json',
        ]));
        self::assertStringContainsString('Could not write the parity report', $tester->getDisplay());
    }

    public function testBothNullProductResultsCannotCompareVacuously(): void
    {
        $command = $this->command();
        $command->core = ['data' => ['products' => null]];
        $command->candidate = $command->core + ['extensions' => ['catalogStorefront' => ['mode' => Mode::DOCUMENTS]]];
        self::assertSame(1, (new CommandTester($command))->execute($this->input() + ['--warm' => '0', '--attempts' => '1']));
    }

    public function testAggregationOnlyQueryDoesNotRequireUnselectedItems(): void
    {
        file_put_contents($this->directory . '/01-product.graphql', '{ products { aggregations { attribute_code } } }');
        $command = $this->command();
        $command->core = ['data' => ['products' => ['aggregations' => [['attribute_code' => 'color']]]]];
        $command->candidate = $command->core + ['extensions' => ['catalogStorefront' => ['mode' => Mode::DOCUMENTS]]];
        self::assertSame(0, (new CommandTester($command))->execute($this->input() + ['--warm' => '0', '--attempts' => '1']));
    }

    public function testAQueryIsSkippedWhereTheRunDoesNotMeetItsHeaderRequirements(): void
    {
        file_put_contents($this->directory . '/02-signed-in.graphql', "# @requires Authorization\n{ customer { email } }");
        file_put_contents($this->directory . '/03-guest.graphql', "# @requires !Authorization\n{ cart(cart_id: \"x\") { id } }");

        $command = $this->command();
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute($this->input() + [
            '--warm' => '0', '--report' => $this->directory . '/report.json',
        ]));
        self::assertStringContainsString('SKIP  02-signed-in: the run sends no Authorization header', $tester->getDisplay());
        self::assertStringContainsString('2 of 2 queries identical, 1 skipped', $tester->getDisplay());
        self::assertSame(1, json_decode((string)file_get_contents($this->directory . '/report.json'), true)['skipped']);

        $command = $this->command();
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute($this->input() + [
            '--warm' => '0', '--header' => ['Authorization: Bearer test-secret'],
        ]));
        self::assertStringContainsString('SKIP  03-guest: the run sends the Authorization header', $tester->getDisplay());
    }

    #[DataProvider('badTransportResponses')]
    public function testTransportAndMalformedJsonAreErrors(string|false $body, int $status): void
    {
        $method = new \ReflectionMethod(Parity::class, 'decodeResponse');
        $response = $method->invoke($this->command(), $body, $status);
        self::assertArrayHasKey('errors', $response);
    }

    public static function badTransportResponses(): iterable
    {
        yield 'unreachable' => [false, 0];
        yield 'HTTP failure with data' => ['{"data":{"products":{"items":[{"sku":"test"}]}}}', 503];
        yield 'invalid JSON' => ['<html>error</html>', 200];
        yield 'scalar JSON' => ['true', 200];
        yield 'null JSON' => ['null', 200];
    }

    public function testTheSoakTakesTurnsBetweenTheGuestAndEveryTokenAndSendsBothPathOrders(): void
    {
        file_put_contents($this->directory . '/02-signed-in.graphql', "# @requires Authorization\n{ products { items { sku } } }");
        $command = $this->command();
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute($this->input() + [
            '--warm' => '0',
            '--soak' => '12',
            '--token' => ['first-token', 'second-token'],
            '--report' => $this->directory . '/report.json',
        ]));

        $soak = json_decode((string)file_get_contents($this->directory . '/report.json'), true, flags: JSON_THROW_ON_ERROR)['soak'];
        self::assertSame(['guest', 'token 1', 'token 2'], $soak['contexts']);
        self::assertSame(['01-product'], $soak['queries']['guest']);
        self::assertSame(['01-product', '02-signed-in'], $soak['queries']['token 1']);
        self::assertSame([], $soak['diffs']);
        self::assertTrue($soak['passed']);

        $soaked = array_slice($command->requests, -24);
        $customers = [];
        foreach (array_slice($command->headers, -24) as $position => $headers) {
            if ($position % 2 === 0) {
                $customers[] = $headers['Authorization'] ?? 'guest';
            }
        }
        self::assertSame(
            ['guest', 'Bearer first-token', 'Bearer second-token', 'guest', 'Bearer first-token', 'Bearer second-token'],
            array_slice($customers, 0, 6)
        );
        self::assertSame([Mode::CORE, Mode::DOCUMENTS, Mode::DOCUMENTS, Mode::CORE], array_column(array_slice($soaked, 0, 4), 1));
    }

    public function testTheSoakReportsADiffWithItsRequestNumberAndContext(): void
    {
        $command = $this->command();
        $command->failFrom = 5;
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute($this->input() + [
            '--warm' => '0', '--attempts' => '1', '--soak' => '6', '--report' => $this->directory . '/report.json',
        ]));

        $soak = json_decode((string)file_get_contents($this->directory . '/report.json'), true, flags: JSON_THROW_ON_ERROR)['soak'];
        self::assertFalse($soak['passed']);
        self::assertSame([1, 2, 3, 4, 5, 6], array_column($soak['diffs'], 'request'));
        self::assertSame('guest', $soak['diffs'][0]['context']);
        self::assertStringContainsString('{ products { items { sku } } }', $soak['diffs'][0]['query']);
        self::assertStringContainsString('SOAK  request 1, guest, 01-product', $tester->getDisplay());
    }

    public function testTheSoakSamplesTheWorkerMemoryAndFailsOnASlopeAboveTheLimit(): void
    {
        $command = $this->command();
        $command->rss = [1000, 1100, 1200, 1900, 2600, 3300];
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute($this->input() + [
            '--warm' => '0', '--soak' => '5', '--memory-sample' => '1', '--memory-slope' => '10',
            '--report' => $this->directory . '/report.json',
        ]));

        $soak = json_decode((string)file_get_contents($this->directory . '/report.json'), true, flags: JSON_THROW_ON_ERROR)['soak'];
        self::assertSame([0, 1, 2, 3, 4, 5], array_column($soak['samples'], 'requests'));
        self::assertSame([1000, 1100, 1200, 1900, 2600, 3300], array_column($soak['samples'], 'rss'));
        self::assertSame(70000.0, (float)$soak['slope']);
        self::assertFalse($soak['passed']);
        self::assertStringContainsString('above 10.0', $tester->getDisplay());
    }

    public function testAProbeRequestIsSentOnItsShareOfTheSoakRequests(): void
    {
        $command = $this->command();
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute($this->input() + [
            '--warm' => '0', '--soak' => '8', '--probe-header' => ['X-Probe: on'], '--probe-share' => '50',
            '--report' => $this->directory . '/report.json',
        ]));

        $soak = json_decode((string)file_get_contents($this->directory . '/report.json'), true, flags: JSON_THROW_ON_ERROR)['soak'];
        self::assertSame(['X-Probe'], $soak['probeHeaders']);
        self::assertSame(4, $soak['probes']);
        self::assertCount(4, array_filter($command->headers, static fn(array $headers) => isset($headers['X-Probe'])));
    }

    #[DataProvider('variations')]
    public function testTheVariationStepsThroughTheValuesTheQueryNames(int $index, string $expected): void
    {
        self::assertSame($expected, Parity::vary('query List($pageSize: Int = 8) { products(filter: { sku: { in: ["a", "b", "c"] } }, pageSize: 4) }', $index));
    }

    public static function variations(): iterable
    {
        yield 'whole' => [0, 'query List($pageSize: Int = 8) { products(filter: { sku: { in: ["a", "b", "c"] } }, pageSize: 4) }'];
        yield 'half' => [1, 'query List($pageSize: Int = 4) { products(filter: { sku: { in: ["b", "c", "a"] } }, pageSize: 2) }'];
        yield 'quarter' => [2, 'query List($pageSize: Int = 2) { products(filter: { sku: { in: ["c", "a", "b"] } }, pageSize: 1) }'];
        yield 'one less' => [3, 'query List($pageSize: Int = 7) { products(filter: { sku: { in: ["a", "b", "c"] } }, pageSize: 3) }'];
    }

    public function testAPageSizeNeverFallsBelowOneAndAListKeepsEveryValue(): void
    {
        self::assertSame(
            '{ products(filter: { sku: { in: ["a"] } }, pageSize: 1) }',
            Parity::vary('{ products(filter: { sku: { in: ["a"] } }, pageSize: 1) }', 3)
        );
    }

    #[DataProvider('slopes')]
    public function testTheSlopeMeasuresTheSecondHalfOfTheRun(array $samples, ?float $expected): void
    {
        self::assertSame($expected, Parity::slope($samples));
    }

    public static function slopes(): iterable
    {
        yield 'one sample' => [[['requests' => 0, 'rss' => 100]], null];
        yield 'second half of two samples' => [[['requests' => 0, 'rss' => 100], ['requests' => 100, 'rss' => 400]], null];
        yield 'flat' => [[['requests' => 0, 'rss' => 100], ['requests' => 100, 'rss' => 100], ['requests' => 200, 'rss' => 100]], 0.0];
        // The warm-up of the first half is left out: only the 100 KB per 100 requests of the second half count.
        yield 'warm-up then flat' => [[
            ['requests' => 0, 'rss' => 100], ['requests' => 100, 'rss' => 900],
            ['requests' => 200, 'rss' => 1000], ['requests' => 300, 'rss' => 1100], ['requests' => 400, 'rss' => 1200],
        ], 100.0];
        yield 'one request count' => [[['requests' => 10, 'rss' => 100], ['requests' => 10, 'rss' => 200]], null];
    }

    private function input(): array
    {
        return ['endpoint' => 'https://reference.example/graphql', '--queries' => $this->directory];
    }

    private function command(): Parity
    {
        $config = $this->createStub(Config::class);
        $config->method('key')->willReturn('diagnostic-key');

        return new class($config, $this->createStub(EavConfig::class), $this->createStub(Uid::class)) extends Parity {
            public array $requests = [];
            public array $headers = [];
            public ?array $core = null;
            public ?array $candidate = null;
            public ?int $failFrom = null;
            public array $rss = [];
            private int $samples = 0;

            protected function request(string $endpoint, string $query, string $mode, string $key, array $headers): array
            {
                $this->requests[] = [$endpoint, $mode];
                $this->headers[] = $headers;
                $failing = $this->failFrom !== null && count($this->requests) >= $this->failFrom;

                return ($mode === Mode::CORE ? $this->core : $this->candidate) ?? [
                    'data' => ['products' => ['items' => [['sku' => $failing && $mode === Mode::DOCUMENTS ? 'changed' : 'test']]]],
                    'extensions' => ['catalogStorefront' => ['mode' => $mode]],
                ];
            }

            protected function workerMemory(string $container, string $process): ?array
            {
                $rss = $this->rss[$this->samples++] ?? 0;

                return ['rss' => $rss, 'hwm' => $rss];
            }
        };
    }
}
