<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Read\DocumentHydration;
use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\Catalog\Model\Product\Type;
use Magento\CatalogGraphQl\Model\Resolver\Product\Price\Discount;
use Magento\CatalogGraphQl\Model\Resolver\Product\PriceRange;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Weee\Helper\Data as WeeeHelper;

/**
 * Serves price_range from the price slices of the document.
 *
 * A single product ranges over its own price row. A configurable uses the
 * ranges DocumentHydration attached from the price aggregation over its
 * variants: enabled variants pass the stock filter of the core
 * ConfigurableOptionsCompositeFilter, and the regular and final minima and
 * maxima are taken independently, as the core configurable price provider
 * does.
 *
 * The feed prices are base-currency amounts without tax, so the core resolver
 * keeps every case that needs a conversion: another display currency, tax
 * display or catalog prices including tax, fixed product taxes, and product
 * types other than simple, virtual, downloadable and configurable.
 */
class PriceRangeFromDocument
{
    public function __construct(
        private readonly Discount $discount,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly TaxConfig $taxConfig,
        private readonly WeeeHelper $weeeHelper,
        private readonly DocumentHydration $hydration,
        private readonly ProductPrice $productPrice,
    ) {
    }

    public function aroundResolve(
        PriceRange $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $product = $value['model'] ?? null;
        $document = $product?->getData(ProductModelBuilder::DOCUMENT_KEY);
        /** @var StoreInterface $store */
        $store = $context->getExtensionAttributes()->getStore();
        if (!is_array($document) || !$this->servable($store)) {
            return $proceed($field, $context, $info, $value, $args);
        }

        $range = match ($product->getTypeId()) {
            Type::TYPE_SIMPLE, Type::TYPE_VIRTUAL, DownloadableType::TYPE_DOWNLOADABLE => $this->singleRange(
                $document,
                $this->hydration->groupKey($context)
            ),
            Configurable::TYPE_CODE => $this->configurableRange(
                $document,
                $product->getData(DocumentHydration::PRICE_RANGE_KEY)
            ),
            default => null,
        };
        if ($range === null) {
            return $proceed($field, $context, $info, $value, $args);
        }
        [$minRegular, $minFinal, $maxRegular, $maxFinal] = $range;

        $currency = $store->getCurrentCurrencyCode();
        $format = fn(float $regular, float $final): array => [
            'regular_price' => ['value' => $this->priceCurrency->roundPrice($regular), 'currency' => $currency],
            'final_price' => ['value' => $this->priceCurrency->roundPrice($final), 'currency' => $currency],
            'discount' => $this->discount->getDiscountByDifference($regular, $final),
            'model' => $product,
        ];
        $empty = [
            'regular_price' => ['value' => null, 'currency' => null],
            'final_price' => ['value' => null, 'currency' => null],
            'discount' => null,
        ];
        $requested = $info->getFieldSelection(10);
        $result = [
            'minimum_price' => !empty($requested['minimum_price']) ? $format($minRegular, $minFinal) : $empty,
            'maximum_price' => !empty($requested['maximum_price']) ? $format($maxRegular, $maxFinal) : $empty,
        ];

        // Core adds the separately purchased link prices to the rounded maximum
        // after the discount is computed.
        if (!empty($document['linksPurchasedSeparately'])) {
            $linkPrice = 0.0;
            foreach ((array)($document['optionsV2'] ?? []) as $option) {
                if (($option['type'] ?? null) === 'downloadable') {
                    $linkPrice += array_sum(array_column((array)($option['values'] ?? []), 'price'));
                }
            }
            if ($linkPrice > 0) {
                $result['maximum_price']['regular_price']['value'] += $linkPrice;
                $result['maximum_price']['final_price']['value'] += $linkPrice;
            }
        }

        return $result;
    }

    private function servable(StoreInterface $store): bool
    {
        return $store->getCurrentCurrencyCode() === $store->getBaseCurrencyCode()
            && !$this->taxConfig->priceIncludesTax($store)
            && (int)$this->taxConfig->getPriceDisplayType($store) === TaxConfig::DISPLAY_TYPE_EXCLUDING_TAX
            && !$this->weeeHelper->isEnabled($store);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null [minRegular, minFinal, maxRegular, maxFinal]
     */
    private function singleRange(array $document, string $groupKey): ?array
    {
        $row = $this->productPrice->row((array)($document['prices'] ?? []), $groupKey);
        if ($row === null) {
            return null;
        }
        $regular = (float)$row['regular'];
        $final = $this->productPrice->finalPrice($row);

        return [$regular, $final, $regular, $final];
    }

    /**
     * @param array{salable: ?array, all: ?array}|null $ranges aggregated over the variants, null when not attached
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function configurableRange(array $document, ?array $ranges): ?array
    {
        if ($ranges === null) {
            return null;
        }
        $parentSalable = (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
        $range = $this->stockConfiguration->isShowOutOfStock()
            ? ($parentSalable ? ($ranges['salable'] ?? $ranges['all']) : $ranges['all'])
            : $ranges['salable'];

        return $range ?? [0.0, 0.0, 0.0, 0.0];
    }
}
