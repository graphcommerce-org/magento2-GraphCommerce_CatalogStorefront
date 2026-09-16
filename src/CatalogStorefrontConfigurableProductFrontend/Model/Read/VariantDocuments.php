<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
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
    /**
     * Per store view: the child ids asked for, the ids an answer covers, and the documents found.
     * A failed request adds to the first and to neither of the others.
     *
     * @var array<string, array{attempted: array<int, true>, ids: array<int, true>, documents: array<int, array>}>
     */
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
        $wanted = array_fill_keys($childIds, true);

        // A row of cards loads while the page renders, so a parent this class has not seen yet can
        // appear after the first fetch. Asking for one is what makes it look again.
        if (array_diff_key($wanted, $this->primed[$storeViewCode]['attempted'] ?? [])) {
            $this->fetch($storeViewCode);
        }

        $primed = $this->primed[$storeViewCode];
        if (array_diff_key($wanted, $primed['ids'])) {
            return null;
        }

        return array_intersect_key($primed['documents'], $wanted);
    }

    /**
     * Fetches the children of every parent on the page this class has not asked for yet, in one
     * request.
     *
     * An id that was asked for is never asked for again, so a row of cards costs one request and
     * not one per card. Whether the answer covers it is a second question: a request that fails
     * covers nothing, so the caller fetches its own children and its log says the store could not
     * answer rather than that the feed is behind.
     */
    private function fetch(string $storeViewCode): void
    {
        $primed = $this->primed[$storeViewCode] ?? ['attempted' => [], 'ids' => [], 'documents' => []];

        $ids = [];
        foreach ($this->listing->documents($storeViewCode) as $document) {
            foreach (self::childIds($document) as $id) {
                if (!isset($primed['attempted'][$id])) {
                    $ids[$id] = true;
                }
            }
        }
        if ($ids === []) {
            $this->primed[$storeViewCode] = $primed;

            return;
        }

        try {
            $documents = $this->products->documents($storeViewCode, array_keys($ids));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'catalog-storefront variant page fetch: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $primed['attempted'] += $ids;
            $this->primed[$storeViewCode] = $primed;

            return;
        }

        $this->primed[$storeViewCode] = [
            'attempted' => $primed['attempted'] + $ids,
            'ids' => $primed['ids'] + $ids,
            'documents' => $primed['documents'] + $documents,
        ];
    }

    public function _resetState(): void
    {
        $this->primed = [];
    }
}
