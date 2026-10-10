<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Plugin;

use GraphCommerce\CatalogStorefrontSearch\Model\Config;
use Magento\Elasticsearch\SearchAdapter\QueryContainer;

/**
 * The search query the price navigation sends again for its stats and its
 * histograms reads aggregations only: no hits, no sort, no page offset, no
 * total, and no scores, so the query runs as a filter. The engine then skips
 * the relevance scoring of a text search and answers the clauses from its
 * filter cache; without hits it can also answer the whole query from its
 * shard request cache. A query over a point in time (a page past the result
 * window) stays as it is.
 */
class LeanPriceQuery
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function afterGetQuery(QueryContainer $subject, array $query): array
    {
        if (!isset($query['index']) || !$this->config->priceRangesFromResponse()) {
            return $query;
        }
        unset($query['body']['from'], $query['body']['sort'], $query['body']['aggregations'],
            $query['body']['stored_fields'], $query['body']['docvalue_fields']);
        if (isset($query['body']['query'])) {
            $query['body']['query'] = ['bool' => ['filter' => [$query['body']['query']]]];
        }
        $query['body']['size'] = 0;
        $query['body']['track_total_hits'] = false;

        return $query;
    }
}
