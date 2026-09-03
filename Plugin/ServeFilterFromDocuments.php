<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin;

use GraphCommerce\CatalogStorefront\Model\Read\DocumentHydration;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * Serves the filter path from feed documents. Product resolves its own
 * collection, so this keeps the resolved id order and swaps only hydration.
 */
class ServeFilterFromDocuments
{
    public function __construct(
        private readonly DocumentHydration $hydration,
    ) {
    }

    public function afterGetList(
        Product $subject,
        SearchResultsInterface $result,
        SearchCriteriaInterface $searchCriteria,
        array $attributes = [],
        bool $isSearch = false,
        bool $isChildSearch = false,
        ?ContextInterface $context = null
    ): SearchResultsInterface {
        if (!$this->hydration->enabled() || !$result->getItems()) {
            return $result;
        }

        return $this->hydration->rebuildFromIds(
            array_map(static fn($item) => (int)$item->getId(), $result->getItems()),
            (int)$result->getTotalCount(),
            $searchCriteria,
            $context
        ) ?? $result;
    }
}
