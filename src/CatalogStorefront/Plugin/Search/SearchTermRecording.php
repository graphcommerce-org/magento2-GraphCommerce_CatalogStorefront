<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Search;

use GraphCommerce\CatalogStorefront\Model\Config;
use Magento\Search\Model\Query;

/**
 * Skips the search term popularity and result count writes when the
 * configuration turns search term recording off.
 */
class SearchTermRecording
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function aroundSaveIncrementalPopularity(Query $subject, \Closure $proceed): Query
    {
        return $this->config->recordSearchTerms() ? $proceed() : $subject;
    }

    public function aroundSaveNumResults(Query $subject, \Closure $proceed, $numResults): Query
    {
        return $this->config->recordSearchTerms() ? $proceed($numResults) : $subject;
    }
}
