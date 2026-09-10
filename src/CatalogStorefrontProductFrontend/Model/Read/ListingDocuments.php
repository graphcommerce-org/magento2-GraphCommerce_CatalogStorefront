<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Model\Read;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The documents the page's rendered products are served from, keyed by product id.
 *
 * A module that needs the whole page, and not one product at a time, reads them here. The page is
 * one fetch; a module that fetches again while a card renders pays a round trip per card.
 *
 * A page carries as many rows of cards as it likes: a category listing, and a detail page's
 * related and upsell rows. Each adds what it holds, so the pool grows as the page renders and a
 * later row never hides an earlier one.
 */
class ListingDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array<int, array>> per store view */
    private array $documents = [];

    /**
     * @param array<int, array> $documents keyed by product id
     */
    public function add(string $storeViewCode, array $documents): void
    {
        $this->documents[$storeViewCode] = ($this->documents[$storeViewCode] ?? []) + $documents;
    }

    /**
     * @return array<int, array> empty where nothing of this store view is served from documents
     */
    public function documents(string $storeViewCode): array
    {
        return $this->documents[$storeViewCode] ?? [];
    }

    public function _resetState(): void
    {
        $this->documents = [];
    }
}
