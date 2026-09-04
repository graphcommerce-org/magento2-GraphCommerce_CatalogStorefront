<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read\Price;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\PrefillRequest;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;

/**
 * The range of a product priced on its own: its regular and final price are
 * both ends.
 */
class SingleRange implements PriceRangeInterface
{
    public function __construct(
        private readonly ProductPrice $productPrice,
    ) {
    }

    public function range(int $productId, array $document, PrefillRequest $request, bool $showOutOfStock): ?array
    {
        $row = $this->productPrice->row((array)($document['prices'] ?? []), $request->groupKey);
        if ($row === null) {
            return null;
        }
        $regular = (float)$row['regular'];
        $final = $this->productPrice->finalPrice($row);

        return [$regular, $final, $regular, $final];
    }
}
