<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Model\Config\Backend;

use Magento\Elasticsearch\SearchAdapter\ConnectionManager;
use Magento\Elasticsearch\SearchAdapter\SearchIndexNameResolver;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A save puts the result window on the product search index of every store
 * view that has one, so the setting holds without a reindex; an empty window
 * gives the index back the engine's own limit. The OpenSearch and the
 * Elasticsearch 8 client both carry the settings and exists calls.
 */
class ResultWindow extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly ConnectionManager $connectionManager,
        private readonly SearchIndexNameResolver $indexNameResolver,
        private readonly StoreManagerInterface $storeManager,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function afterSave(): self
    {
        $window = (int)$this->getValue();
        $client = $this->connectionManager->getConnection();
        foreach ($this->storeManager->getStores() as $store) {
            $index = $this->indexNameResolver->getIndexName((int)$store->getId(), 'catalogsearch_fulltext');
            if ($client->indexExists($index)) {
                $client->putIndexSettings($index, ['index' => ['max_result_window' => $window > 0 ? $window : null]]);
            }
        }

        return parent::afterSave();
    }
}
