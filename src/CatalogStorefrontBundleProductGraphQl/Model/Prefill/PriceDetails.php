<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProductGraphQl\Model\Prefill;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Read\PriceDisplay;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;
use Magento\Catalog\Model\Product\Type;

/**
 * A bundle's price_details: its own price, that price after the bundle's
 * pay percent, and the discount between them.
 */
class PriceDetails implements PrefillerInterface
{
    public function __construct(
        private readonly PriceDisplay $priceDisplay,
        private readonly ProductPrice $productPrice,
    ) {
    }

    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('price_details') || !$this->priceDisplay->servable($request->store)) {
            return [];
        }
        $output = [];
        foreach ($models as $id => $product) {
            $row = $this->productPrice->row((array)($documents[$id]['prices'] ?? []), $request->groupKey);
            if ($product->getTypeId() !== Type::TYPE_BUNDLE || $row === null) {
                continue;
            }
            $mainPrice = (float)$product->getData('price');
            $payPercent = $this->productPrice->bundlePayPercent($row);
            $mainFinalPrice = $payPercent === null ? $mainPrice : round($mainPrice * $payPercent / 100, 2);
            $output[$id]['price_details'] = [
                'main_price' => $mainPrice,
                'main_final_price' => $mainFinalPrice,
                'discount_percentage' => $mainPrice ? 100 - ($mainFinalPrice * 100 / $mainPrice) : 0,
            ];
        }

        return $output;
    }
}
