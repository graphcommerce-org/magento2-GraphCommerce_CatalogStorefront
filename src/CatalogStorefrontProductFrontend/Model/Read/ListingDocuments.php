<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Model\Read;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The documents of the page's listing rows, and the price data of their composite products,
 * for what a card needs beyond its own document.
 */
class ListingDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array<int, array>> by store view code, by product id */
    private array $documents = [];

    /** @var array<string, array<string, array<int, mixed>>> by store view code, as the storage's listing() returns it */
    private array $priceData = [];

    public function add(string $storeViewCode, array $documents, array $priceData = []): void
    {
        $this->documents[$storeViewCode] = ($this->documents[$storeViewCode] ?? []) + $documents;
        foreach ($priceData as $kind => $byId) {
            $this->priceData[$storeViewCode][$kind] = ($this->priceData[$storeViewCode][$kind] ?? []) + $byId;
        }
    }

    public function documents(string $storeViewCode): array
    {
        return $this->documents[$storeViewCode] ?? [];
    }

    public function priceData(string $storeViewCode): array
    {
        return $this->priceData[$storeViewCode] ?? [];
    }

    public function _resetState(): void
    {
        $this->documents = [];
        $this->priceData = [];
    }
}
