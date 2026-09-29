<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read;

use GraphCommerce\CatalogStorefrontPrice\Model\Read\DisplayPrice;
use GraphCommerce\CatalogStorefrontApi\Read\Amount;
use GraphCommerce\CatalogStorefrontApi\Read\DocumentContext;
use GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface;
use Magento\Catalog\Model\Product;

/**
 * A configurable's range is the aggregation over its variants' price index:
 * the salable variants, or all enabled variants when out-of-stock products
 * are shown and the parent itself is not salable. Without a variant that
 * prices it, core answers zero. Core taxes every variant's amounts with the
 * variant's own tax class and then takes the lowest and the highest by
 * value, so each bound is taken over the per tax class bounds, each taxed
 * with its class.
 */
class ConfigurableRange implements PriceRangeInterface
{
    public function __construct(
        private readonly DisplayPrice $displayPrice,
    ) {
    }

    public function range(Product $product, array $document, DocumentContext $context, bool $showOutOfStock): ?array
    {
        $ranges = $context->priceData()['configurable'][(int)$product->getId()] ?? null;
        if ($ranges === null) {
            return null;
        }
        $parentSalable = (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
        $range = $showOutOfStock
            ? ($parentSalable ? ($ranges['salable'] ?? $ranges['all']) : $ranges['all'])
            : $ranges['salable'];
        if ($range === null) {
            return [new Amount(0.0), new Amount(0.0), new Amount(0.0), new Amount(0.0)];
        }
        $store = $context->store;
        $byTaxClass = $range[4] ?? [];
        if (!$byTaxClass) {
            $byTaxClass = [(int)$product->getTaxClassId() => $range];
        }
        $bounds = [];
        foreach ($byTaxClass as $taxClassId => $taxRange) {
            [$minRegular, $minFinal, $maxRegular, $maxFinal] = $taxRange;
            $taxClassId = $taxRange['taxClassId'] ?? $taxClassId;
            $taxes = $taxRange['fixedProductTaxes'] ?? [];
            $variant = $this->displayPrice->forTaxClass($product, $taxClassId ?: null);
            $variant->setTypeId('simple');
            $bounds[] = [
                $this->displayPrice->regular($minRegular, $variant, $store, $taxes),
                $this->displayPrice->final($minFinal, $minRegular, $variant, $store, 4, $taxes),
                $this->displayPrice->regular($maxRegular, $variant, $store, $taxes),
                $this->displayPrice->final($maxFinal, $maxRegular, $variant, $store, 4, $taxes),
            ];
        }

        return [
            $this->extreme(array_column($bounds, 0), false),
            $this->extreme(array_column($bounds, 1), false),
            $this->extreme(array_column($bounds, 2), true),
            $this->extreme(array_column($bounds, 3), true),
        ];
    }

    /**
     * @param Amount[] $amounts
     */
    private function extreme(array $amounts, bool $highest): Amount
    {
        $pick = $amounts[0];
        foreach ($amounts as $amount) {
            if ($highest ? $amount->value > $pick->value : $amount->value < $pick->value) {
                $pick = $amount;
            }
        }

        return $pick;
    }
}
