<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The store view's attribute documents by code, fetched once per request and
 * code: labels, frontend input, flags and the options with their labels, from
 * the product attributes feed.
 */
class AttributeDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array<string, array|null>> per store view and code; null marks a code without a document */
    private array $byStore = [];

    /** @var array<string, bool> per store view: whether the feed has landed */
    private array $available = [];

    private array $all = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    /**
     * @param string[] $codes
     * @return array<string, array> the documents of the codes that have one, keyed by code
     */
    public function byCodes(string $storeViewCode, array $codes): array
    {
        $known = $this->byStore[$storeViewCode] ?? [];
        $missing = array_values(array_diff($codes, array_keys($known)));
        if ($missing) {
            $fetched = $this->storage->get('attribute', $storeViewCode, $missing);
            foreach ($missing as $code) {
                $known[$code] = $fetched[$code] ?? null;
            }
            $this->byStore[$storeViewCode] = $known;
        }

        return array_filter(array_intersect_key($known, array_flip($codes)));
    }

    /**
     * @return array<string, array> every attribute document of the store view, by code
     */
    public function all(string $storeViewCode): array
    {
        return $this->all[$storeViewCode] ??= $this->storage->all('attribute', $storeViewCode);
    }

    public function available(string $storeViewCode): bool
    {
        return $this->available[$storeViewCode] ??= $this->storage->count('attribute', $storeViewCode) > 0;
    }

    public function _resetState(): void
    {
        $this->byStore = [];
        $this->available = [];
        $this->all = [];
    }
}
