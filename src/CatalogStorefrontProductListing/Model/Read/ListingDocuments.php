<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductListing\Model\Read;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The documents the listing page is served from, keyed by product id.
 *
 * A listing module that needs the whole page, and not one product at a time, reads them here. The
 * page is one fetch; a module that fetches again while a card renders pays a round trip per card.
 */
class ListingDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array<int, array>> per store view */
    private array $documents = [];

    /**
     * @param array<int, array> $documents keyed by product id
     */
    public function set(string $storeViewCode, array $documents): void
    {
        $this->documents[$storeViewCode] = $documents;
    }

    /**
     * @return array<int, array> empty where no listing of this store view is served from documents
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
