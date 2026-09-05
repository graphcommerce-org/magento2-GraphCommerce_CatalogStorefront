<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage\Client\Config;

/**
 * Where each search engine keeps its connection details.
 *
 * Magento's own engines use catalog/search/<engine>_server_hostname and siblings. A replacement
 * engine may keep them somewhere else — Smile ElasticSuite uses
 * smile_elasticsuite_core_base_settings/es_client — and then the standard lookup finds nothing and
 * Config quietly falls back to localhost.
 *
 * Give an engine an entry naming the config path for each field. Anything it does not name falls
 * back to the Magento path for that engine, so an entry can override one field and leave the rest.
 * Adding an engine is di.xml only:
 *
 *     <item name="myengine" xsi:type="array">
 *         <item name="host" xsi:type="string">my/engine/host</item>
 *     </item>
 *
 * Protocol is not included: Config hardcodes http rather than reading it from config, so a path
 * mapped here would never be read. An https cluster needs that fixed first.
 */
class ConnectionPathPool
{
    public const HOST = 'host';
    public const PORT = 'port';
    public const USER = 'user';
    public const PASSWORD = 'password';
    public const ENABLE_AUTH = 'enableAuth';

    /**
     * @param array<string, array<string, string>> $engines engine code => field => config path
     */
    public function __construct(
        private readonly array $engines = []
    ) {
    }

    /**
     * Config paths for one engine, field => path, filling any gaps with the Magento paths.
     *
     * @param string $engine
     * @return array<string, string>
     */
    public function getPaths(string $engine): array
    {
        return ($this->engines[$engine] ?? []) + $this->conventionPaths($engine);
    }

    /**
     * The paths Magento's own engines use.
     *
     * @param string $engine
     * @return array<string, string>
     */
    public function conventionPaths(string $engine): array
    {
        $prefix = 'catalog/search/' . $engine;

        return [
            self::HOST => $prefix . '_server_hostname',
            self::PORT => $prefix . '_server_port',
            self::USER => $prefix . '_server_username',
            self::PASSWORD => $prefix . '_server_password',
            self::ENABLE_AUTH => $prefix . '_enable_auth',
        ];
    }
}
