<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\DataProvider;

use GraphCommerce\CatalogStorefrontGraphQl\Model\DocumentHydration;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\ProductSearch;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * Serves the search path from feed documents. ProductSearch already holds the
 * resolved ids in $searchResult, so the collection load is skipped entirely.
 */
class ServeSearchFromDocuments
{
    public function __construct(
        private readonly DocumentHydration $hydration,
    ) {
    }

    public function aroundGetList(
        ProductSearch $subject,
        \Closure $proceed,
        SearchCriteriaInterface $searchCriteria,
        SearchResultInterface $searchResult,
        array $attributes = [],
        ?ContextInterface $context = null
    ): SearchResultsInterface {
        if (!$this->hydration->enabled() || !$searchResult->getItems()) {
            return $proceed($searchCriteria, $searchResult, $attributes, $context);
        }

        $rebuilt = $this->hydration->rebuildFromIds(
            array_map(static fn($item) => (int)$item->getId(), $searchResult->getItems()),
            (int)$searchResult->getTotalCount(),
            $searchCriteria,
            $context,
            $attributes
        );

        return $rebuilt ?? $proceed($searchCriteria, $searchResult, $attributes, $context);
    }
}
