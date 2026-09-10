<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductListing\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontProductListing\Model\Read\ListingDocuments;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Api\Data\StoreInterface;
use Psr\Log\LoggerInterface;

/**
 * The child documents of every configurable on the listing page, in one fetch.
 *
 * A card asks for its children while it renders, so a fetch per parent is a round trip per card:
 * twelve cards cost twelve searches of about nine milliseconds each. The page documents name every
 * child of every parent, so the first card that asks fetches all of them.
 *
 * The fetch covers a listing page only. Anywhere else, and where it fails, the caller fetches its
 * own children and the page costs what it costs without this class.
 */
class VariantDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array{ids: array<int, true>, documents: array<int, array>}> per store view */
    private array $primed = [];

    public function __construct(
        private readonly ProductDocumentsInterface $products,
        private readonly ListingDocuments $listing,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The child ids a parent document names, ascending, so a partially indexed parent falls back
     * rather than rendering a short option list.
     *
     * Keys are prefixed ("v123") to keep the map a JSON object; a null value is a link the feed
     * reports removed.
     *
     * @return int[]
     */
    public static function childIds(array $document): array
    {
        $ids = array_values(array_unique(array_map(
            'intval',
            array_filter((array)($document['variantIds'] ?? []), static fn($id) => $id !== null)
        )));
        sort($ids);

        return $ids;
    }

    /**
     * @param int[] $childIds
     * @return array<int, array>|null the documents that exist, keyed by child id; null where the
     *                                page fetch does not cover the ids
     */
    public function documents(StoreInterface $store, array $childIds): ?array
    {
        $storeViewCode = (string)$store->getCode();
        if (!isset($this->primed[$storeViewCode])) {
            $this->prime($storeViewCode);
        }

        $primed = $this->primed[$storeViewCode];
        if (array_diff_key(array_fill_keys($childIds, true), $primed['ids'])) {
            return null;
        }

        return array_intersect_key($primed['documents'], array_fill_keys($childIds, true));
    }

    /**
     * A store view is primed once, even where the fetch finds nothing or fails, so a page of
     * parents that this class cannot serve makes one attempt and not one per card.
     */
    private function prime(string $storeViewCode): void
    {
        $this->primed[$storeViewCode] = ['ids' => [], 'documents' => []];

        $ids = [];
        foreach ($this->listing->documents($storeViewCode) as $document) {
            foreach (self::childIds($document) as $id) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return;
        }

        try {
            $documents = $this->products->documents($storeViewCode, array_keys($ids));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'catalog-storefront variant page fetch: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return;
        }

        $this->primed[$storeViewCode] = ['ids' => $ids, 'documents' => $documents];
    }

    public function _resetState(): void
    {
        $this->primed = [];
    }
}
