<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Storage\MetadataDocumentStorage;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The store view's active ratings and their value scale, from the rating
 * metadata feed, loaded once per request. A vote's percent is its value over
 * the rating's number of values; a rating the store view does not carry does
 * not count.
 */
class RatingMetadata implements ResetAfterRequestInterface
{
    /** @var array<string, array<int, int>> value count per rating id, per store view */
    private array $scales = [];

    public function __construct(
        private readonly MetadataDocumentStorage $storage,
    ) {
    }

    public function scale(string $storeViewCode, int $ratingId): ?int
    {
        if (!isset($this->scales[$storeViewCode])) {
            $this->scales[$storeViewCode] = [];
            foreach ($this->storage->all($storeViewCode) as $id => $rating) {
                $this->scales[$storeViewCode][(int)$id] = count((array)($rating['values'] ?? []));
            }
        }

        return $this->scales[$storeViewCode][$ratingId] ?? null;
    }

    public function _resetState(): void
    {
        $this->scales = [];
    }
}
