<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontElasticsuite\Test\Unit\Model\Client;

use GraphCommerce\CatalogStorefrontElasticsuite\Model\Client\Options;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class OptionsTest extends TestCase
{
    private const SERVERS = 'smile_elasticsuite_core_base_settings/es_client/servers';
    private const USER = 'smile_elasticsuite_core_base_settings/es_client/http_auth_user';
    private const PASSWORD = 'smile_elasticsuite_core_base_settings/es_client/http_auth_pwd';
    private const TIMEOUT = 'smile_elasticsuite_core_base_settings/es_client/timeout';
    private const ALIAS = 'smile_elasticsuite_core_base_settings/indices_settings/alias';
    private const HTTPS = 'smile_elasticsuite_core_base_settings/es_client/enable_https_mode';
    private const AUTH = 'smile_elasticsuite_core_base_settings/es_client/enable_http_auth';

    /**
     * @param array<string, mixed> $values what getValue answers, by path
     * @param array<string, bool> $flags what isSetFlag answers, by path
     */
    private function options(array $values, array $flags = []): Options
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn(string $path) => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn(string $path) => (bool)($flags[$path] ?? false)
        );

        return new Options($scopeConfig);
    }

    public function testTakesTheFirstServerAndItsPort(): void
    {
        // ElasticSuite round-robins its whole list; core's client connects to one host.
        $options = $this->options([self::SERVERS => 'first:9201,second:9202'])->prepareClientOptions();

        $this->assertSame('http://first', $options['hostname']);
        $this->assertSame(9201, $options['port']);
    }

    public function testDefaultsThePortWhenTheServerCarriesNone(): void
    {
        $options = $this->options([self::SERVERS => 'opensearch'])->prepareClientOptions();

        $this->assertSame(9200, $options['port']);
    }

    public function testIgnoresBlankEntriesInTheServerList(): void
    {
        $options = $this->options([self::SERVERS => ' , opensearch:9200 '])->prepareClientOptions();

        $this->assertSame('http://opensearch', $options['hostname']);
    }

    public function testHttpsModePrefixesTheHostname(): void
    {
        // The scheme travels inside hostname because SearchClient::buildOSConfig parses it back out.
        $options = $this->options(
            [self::SERVERS => 'opensearch:9200'],
            [self::HTTPS => true]
        )->prepareClientOptions();

        $this->assertSame('https://opensearch', $options['hostname']);
    }

    public function testAuthIsSentWhenTheFlagAndBothCredentialsAreSet(): void
    {
        $options = $this->options(
            [self::SERVERS => 'opensearch:9200', self::USER => 'user', self::PASSWORD => 'secret'],
            [self::AUTH => true]
        )->prepareClientOptions();

        $this->assertSame(1, $options['enableAuth']);
        $this->assertSame('user', $options['username']);
        $this->assertSame('secret', $options['password']);
    }

    public function testAuthNeedsBothCredentialsNotJustTheFlag(): void
    {
        // ElasticSuite connects anonymously when either credential is blank. Matching that keeps
        // the two clients on the same footing; sending ":@" would fail where ElasticSuite works.
        $options = $this->options(
            [self::SERVERS => 'opensearch:9200', self::USER => 'user', self::PASSWORD => ''],
            [self::AUTH => true]
        )->prepareClientOptions();

        $this->assertSame(0, $options['enableAuth']);
        $this->assertSame('', $options['username']);
        $this->assertSame('', $options['password']);
    }

    public function testAuthIsNotSentWithoutTheFlag(): void
    {
        $options = $this->options(
            [self::SERVERS => 'opensearch:9200', self::USER => 'user', self::PASSWORD => 'secret']
        )->prepareClientOptions();

        $this->assertSame(0, $options['enableAuth']);
    }

    public function testTimeoutFallsBackToTheCoreDefault(): void
    {
        $options = $this->options([self::SERVERS => 'opensearch:9200'])->prepareClientOptions();

        $this->assertSame(30, $options['timeout']);
    }

    public function testTimeoutIsTakenFromTheConfigurationWhenSet(): void
    {
        $options = $this->options(
            [self::SERVERS => 'opensearch:9200', self::TIMEOUT => '5']
        )->prepareClientOptions();

        $this->assertSame(5, $options['timeout']);
    }

    public function testIndexComesFromTheElasticsuiteAlias(): void
    {
        $options = $this->options(
            [self::SERVERS => 'opensearch:9200', self::ALIAS => 'magento2']
        )->prepareClientOptions();

        $this->assertSame('magento2', $options['index']);
    }

    public function testEmptyServerListThrowsRatherThanGuessing(): void
    {
        // Deliberately no fallback of our own: ElasticSuite already defaults to localhost, so a
        // value invented here would look plausible and be wrong. Do not "helpfully" add one.
        $this->expectException(LocalizedException::class);

        $this->options([self::SERVERS => ''])->prepareClientOptions();
    }

    public function testKeepsOnlyTheKeysCoresClientAcceptsPlusEngine(): void
    {
        $options = $this->options([self::SERVERS => 'opensearch:9200'])
            ->prepareClientOptions(['engine' => 'elasticsuite', 'unexpected' => 'dropped']);

        $this->assertSame('elasticsuite', $options['engine']);
        $this->assertArrayNotHasKey('unexpected', $options);
        $this->assertSame(
            ['hostname', 'port', 'index', 'enableAuth', 'username', 'password', 'timeout', 'engine'],
            array_keys($options)
        );
    }

    public function testCallerSuppliedValuesWin(): void
    {
        $options = $this->options([self::SERVERS => 'opensearch:9200'])
            ->prepareClientOptions(['hostname' => 'http://elsewhere']);

        $this->assertSame('http://elsewhere', $options['hostname']);
    }
}
