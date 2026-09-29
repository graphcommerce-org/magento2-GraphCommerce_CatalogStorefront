<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Model\Prefill;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\FixedProductTax;
use GraphCommerce\CatalogStorefrontPriceGraphQl\Model\Prefill\Prices;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

/**
 * The deprecated price of a configurable: core taxes it with the
 * configurable's own tax class, the lowest variant's base prices through the
 * parent's price info, where price_range taxes every variant with its own
 * class. Runs after the core price prefiller and rewrites what it filled.
 * With fixed product taxes active the configurable went to core.
 */
class DeprecatedPrice implements PrefillerInterface
{
    public function __construct(
        private readonly DisplayPrice $displayPrice,
        private readonly Prices $prices,
        private readonly FixedProductTax $fixedProductTax,
        private readonly Strict $strict,
    ) {
    }

    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('price')) {
            return [];
        }
        if ($this->fixedProductTax->active($request->store)) {
            $this->strict->fallback(self::class, 'fixed product taxes on a configurable');
        }
        $store = $request->store;
        $showOutOfStock = $this->displayPrice->showOutOfStock($store);
        $output = [];
        foreach ($models as $id => $product) {
            if ($product->getTypeId() !== Configurable::TYPE_CODE || !isset($product->getData(self::KEY)['price'])) {
                continue;
            }
            $ranges = $request->priceData()['configurable'][(int)$id] ?? null;
            if ($ranges === null) {
                continue;
            }
            $document = $documents[$id] ?? [];
            $parentSalable = (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
            $range = $showOutOfStock
                ? ($parentSalable ? ($ranges['salable'] ?? $ranges['all']) : $ranges['all'])
                : $ranges['salable'];
            if ($range === null) {
                continue;
            }
            [$minRegular, $minFinal, $maxRegular, $maxFinal] = $range;
            $output[$id]['price'] = [
                'minimalPrice' => $this->prices->amount($this->displayPrice->final($minFinal, $minRegular, $product, $store), $store),
                'regularPrice' => $this->prices->amount($this->displayPrice->regular($minRegular, $product, $store), $store),
                'maximalPrice' => $this->prices->amount($this->displayPrice->final($maxFinal, $maxRegular, $product, $store), $store),
            ];
        }

        return $output;
    }
}
