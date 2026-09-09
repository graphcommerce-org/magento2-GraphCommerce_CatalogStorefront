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
            public ?array $core = null;
            public ?array $candidate = null;

            protected function request(string $endpoint, string $query, string $mode, string $key, array $headers): array
            {
                $this->requests[] = [$endpoint, $mode];

                return ($mode === Mode::CORE ? $this->core : $this->candidate) ?? [
                    'data' => ['products' => ['items' => [['sku' => 'test']]]],
                    'extensions' => ['catalogStorefront' => ['mode' => $mode]],
                ];
            }
        };
    }
}
