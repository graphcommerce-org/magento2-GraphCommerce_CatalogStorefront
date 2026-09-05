<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Storage\Client;

use GraphCommerce\CatalogStorefront\Model\Storage\Client\Config\ConnectionPathPool;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\Config\EntityConfigInterface;
use GraphCommerce\CatalogStorefront\Model\Storage\Client\Config\EntityConfigPool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig\Reader;
use Magento\Framework\Exception\ConfigurationMismatchException;
use Magento\Framework\Config\File\ConfigFilePool;

/**
 * Storage connection and index-naming config, read from app/etc/env.php:
 * 'catalog-store-front' => [
 *     'connections' => [
 *         'default' => [
 *             'protocol' => 'http',
 *             'hostname' => 'localhost',
 *             'port' => '9200',
 *             'username' => '',
 *             'password' => '',
 *             'timeout' => 3
 *         ]
 *     ],
 *     'timeout' => 60,
 *     'alias_name' => 'catalog_storefront',
 *     'source_prefix' => 'catalog_storefront_v',
 *     'source_current_version' => 1
 * ]
 *
 * For now you only has to follow the structure and modify value of each option to appropriate that correspond to
 * your environment. It's possible by particularly modify the app/etc/env.php file that is the representation
 * of connection configuration in the Magento application.
 *
 * TODO: MC-29894
 */
class Config
{
    /**
     * Default Application config.
     *
     * @var array
     */
    private static $DEFAULT_CONFIG = [
        'connections' => [
            'default' => [
                'protocol' => 'http',
                'hostname' => 'localhost',
                'port' => '9200',
                'username' => '',
                'password' => '',
                'timeout' => 3
            ]
        ],
        'timeout' => 60,
        'alias_name' => 'catalog_storefront',
        'source_prefix' => 'catalog_storefront_v',
        'source_current_version' => 1
    ];

    /**
     * @var array
     */
    private $connectionConfig;

    /**
     * @var array
     */
    private $config;

    /**
     * @var EntityConfigPool
     */
    private $entityConfigPool;

    /**
     * @var ConnectionPathPool
     */
    private $connectionPathPool;

    /**
     * Initialize Elasticsearch Client
     *
     * @param Reader $configReader
     * @param EntityConfigPool $entityConfigPool
     * @throws ConfigurationMismatchException
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\RuntimeException
     */
    public function __construct(
        Reader $configReader,
        EntityConfigPool $entityConfigPool,
        ScopeConfigInterface $scopeConfig,
        ConnectionPathPool $connectionPathPool
    ) {
        $this->connectionPathPool = $connectionPathPool;
        $configData = $configReader->load(ConfigFilePool::APP_ENV);
        $this->config = isset($configData['catalog-store-front'])
            ? array_replace_recursive($this->defaultConfig($scopeConfig), $configData['catalog-store-front'])
            : $this->defaultConfig($scopeConfig);
        $options = $this->config['connections']['default'];

        if (empty($options['hostname']) || ((!empty($options['enableAuth'])
                    && ($options['enableAuth'] == 1)) && (empty($options['username']) || empty($options['password'])))
        ) {
            throw new ConfigurationMismatchException(
                __('The search failed because of a search engine misconfiguration.')
            );
        }
        $this->connectionConfig = $options;
        $this->entityConfigPool = $entityConfigPool;
    }

    /**
     * The connection defaults follow Magento's own search-engine config, so the
     * document store reaches the same host as the rest of the application in
     * every runtime (php-fpm, worker container, cli), including env overrides.
     */
    private function defaultConfig(ScopeConfigInterface $scopeConfig): array
    {
        $engine = (string)$scopeConfig->getValue('catalog/search/engine') ?: 'opensearch';
        $configPaths = $this->connectionPathPool->getPaths($engine);

        $config = self::$DEFAULT_CONFIG;
        $config['connections']['default'] = [
            'protocol' => 'http',
            'hostname' => (string)$scopeConfig->getValue($configPaths[ConnectionPathPool::HOST]) ?: 'localhost',
            'port' => (string)$scopeConfig->getValue($configPaths[ConnectionPathPool::PORT]) ?: '9200',
            'username' => (string)$scopeConfig->getValue($configPaths[ConnectionPathPool::USER]),
            'password' => (string)$scopeConfig->getValue($configPaths[ConnectionPathPool::PASSWORD]),
            'enableAuth' => (int)$scopeConfig->getValue($configPaths[ConnectionPathPool::ENABLE_AUTH]),
            'timeout' => 3,
        ];

        $this->parseHostname($config);

        return $config;
    }

    /**
     * passes the config array by reference and parses the hostname for multiple hosts
     * and if the hostname includes the port. eg. the smile elastic suite module's setup
     *
     * @param array $config
     * @return void
     */
    private function parseHostname(array &$config)
    {
        if (
            strpos($config['connections']['default']['hostname'], ",") === false &&
            strpos($config['connections']['default']['hostname'], ":") === false
        ) {
            return;
        }

        if (strpos($config['connections']['default']['hostname'], ",") !== false) {
            $hostNames = explode(",", $config['connections']['default']['hostname']);
            $config['connections']['default']['hostname'] = $hostNames[0];
        }

        if (
            $config['connections']['default']['hostname'] === $config['connections']['default']['port'] && 
            strpos($config['connections']['default']['hostname'], ":") !== false
        ) {
            $hostPortArray = explode(":", $config['connections']['default']['hostname']);
            $config['connections']['default']['hostname'] = $hostPortArray[0];
            $config['connections']['default']['port'] = $hostPortArray[1];
        }

        return;
    }

    /**
     * Return connection config of the Client.
     *
     * @return array
     */
    public function getConnectionConfig()
    {
        return $this->connectionConfig;
    }

    /**
     * Get entity config instance.
     *
     * @param string $entityName
     * @return EntityConfigInterface
     * @throws \Magento\Framework\Exception\NotFoundException
     */
    public function getEntityConfig(string $entityName): EntityConfigInterface
    {
        return $this->entityConfigPool->getConfig($entityName);
    }

    /**
     * Get alias name.
     *
     * @return string
     */
    public function getAliasName(): string
    {
        return $this->config['alias_name'];
    }

    /**
     * Get source prefix.
     *
     * @return string
     */
    public function getSourcePrefix(): string
    {
        return $this->config['source_prefix'];
    }

    /**
     * Get current source version.
     *
     * @return int
     */
    public function getCurrentSourceVersion(): int
    {
        return $this->config['source_current_version'];
    }

    /**
     * Build config.
     *
     * @return array
     */
    public function buildConfig()
    {
        $portString = '';
        if (!empty($this->connectionConfig['port'])) {
            $portString = ':' . $this->connectionConfig['port'];
        }

        $host = $this->connectionConfig['protocol'] . '://' . $this->connectionConfig['hostname'] . $portString;

        $result['hosts'] = [$host];

        return $result;
    }
}
