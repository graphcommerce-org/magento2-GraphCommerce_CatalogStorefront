<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGroupedProductGraphQl\Model\Prefill;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;
use Magento\GroupedProduct\Model\Product\Type\Grouped;

/**
 * The deprecated price of a grouped product: its regular price is the
 * product's own price attribute, zero, where the range holds the lowest
 * child price. Runs after the core price prefiller and rewrites what it
 * filled.
 */
class DeprecatedPrice implements PrefillerInterface
{
    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('price')) {
            return [];
        }
        $output = [];
        foreach ($models as $id => $product) {
            $price = $product->getData(self::KEY)['price'] ?? null;
            if ($product->getTypeId() !== Grouped::TYPE_CODE || $price === null) {
                continue;
            }
            $price['regularPrice']['amount']['value'] = (float)$product->getData('price');
            $output[$id]['price'] = $price;
        }

        return $output;
    }
}
