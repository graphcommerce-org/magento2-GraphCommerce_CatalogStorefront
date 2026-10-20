<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model\Read\VariantDocuments;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Answers the configurable parents of a card product, or of a variant of a card, from its
 * document. Core's identities plugin asks them for every simple product whose identities a card
 * collects, a query per product on every render, also when the card's block cache hits. A product
 * whose document the page did not read goes to core.
 */
class ParentIdsFromDocuments
{
    public function __construct(
        private readonly ListingDocuments $page,
        private readonly VariantDocuments $variants,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @param Configurable $subject
     * @param \Closure $proceed
     * @param int|string|int[]|string[] $childId
     * @return string[]
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetParentIdsByChild(Configurable $subject, \Closure $proceed, $childId)
    {
        $store = $this->storeManager->getStore();
        $cards = $this->page->documents((string)$store->getCode());
        if ($cards === []) {
            return $proceed($childId);
        }

        $ids = array_map('intval', (array)$childId);
        $documents = array_intersect_key($cards, array_flip($ids));
        $rest = array_values(array_diff($ids, array_keys($documents)));
        if ($rest !== []) {
            $variants = $this->variants->documents($store, $rest);
            if ($variants === null || count($variants) !== count($rest)) {
                return $proceed($childId);
            }
            $documents += $variants;
        }

        $parentIds = [];
        foreach ($documents as $document) {
            foreach ((array)($document['parentIds'] ?? []) as $parentId) {
                $parentIds[(string)$parentId] = (string)$parentId;
            }
        }

        return array_values($parentIds);
    }
}
