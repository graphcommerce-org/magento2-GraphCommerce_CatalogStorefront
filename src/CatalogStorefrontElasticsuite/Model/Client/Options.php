<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontElasticsuite\Model\Client;

use Magento\AdvancedSearch\Model\Client\ClientOptionsInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Search client options for the `elasticsuite` engine, answered from ElasticSuite's
 * own settings so the document store and search reach the same cluster.
 */
class Options implements ClientOptionsInterface
{
    private const CLIENT = 'smile_elasticsuite_core_base_settings/es_client/';
    private const INDICES = 'smile_elasticsuite_core_base_settings/indices_settings/';
    private const DEFAULT_PORT = 9200;
    private const DEFAULT_TIMEOUT = 30;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * @param array $options
     * @return array
     * @throws LocalizedException
     */
    public function prepareClientOptions($options = [])
    {
        [$host, $port] = $this->firstServer();
        $auth = $this->authEnabled();

        $defaults = [
            'hostname' => $this->scheme() . '://' . $host,
            'port' => $port,
            'index' => (string)$this->scopeConfig->getValue(self::INDICES . 'alias'),
            'enableAuth' => $auth ? 1 : 0,
            'username' => $auth ? $this->user() : '',
            'password' => $auth ? $this->password() : '',
            'timeout' => (int)$this->scopeConfig->getValue(self::CLIENT . 'timeout') ?: self::DEFAULT_TIMEOUT,
        ];

        $options = array_merge($defaults, $options);
        $allowed = array_merge(array_keys($defaults), ['engine']);

        return array_filter(
            $options,
            static fn (string $key): bool => in_array($key, $allowed, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * The first of ElasticSuite's servers. No default: ElasticSuite already
     * defaults to localhost, so one of ours would look right and be wrong.
     *
     * @return array{0: string, 1: int}
     * @throws LocalizedException
     */
    private function firstServer(): array
    {
        $servers = (string)$this->scopeConfig->getValue(self::CLIENT . 'servers');
        foreach (explode(',', $servers) as $server) {
            $server = trim($server);
            if ($server === '') {
                continue;
            }
            [$host, $port] = array_pad(explode(':', $server, 2), 2, self::DEFAULT_PORT);

            return [$host, (int)$port];
        }

        throw new LocalizedException(
            __(
                'The catalog document store has no server to connect to. Set Stores > Configuration'
                . ' > Smile ElasticSuite > Base Settings > ElasticSearch Client > ElasticSearch Servers.'
            )
        );
    }

    private function scheme(): string
    {
        return $this->scopeConfig->isSetFlag(self::CLIENT . 'enable_https_mode') ? 'https' : 'http';
    }

    /**
     * ElasticSuite sends credentials only with the flag on and both fields filled.
     */
    private function authEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::CLIENT . 'enable_http_auth')
            && $this->user() !== ''
            && $this->password() !== '';
    }

    private function user(): string
    {
        return (string)$this->scopeConfig->getValue(self::CLIENT . 'http_auth_user');
    }

    private function password(): string
    {
        return (string)$this->scopeConfig->getValue(self::CLIENT . 'http_auth_pwd');
    }
}
