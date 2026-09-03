<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\DocumentHydration;
use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\Catalog\Model\Product\Type;
use Magento\CatalogGraphQl\Model\Resolver\Product\Price\Discount;
use Magento\CatalogGraphQl\Model\Resolver\Product\PriceRange;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Weee\Helper\Data as WeeeHelper;

/**
 * Serves price_range from the price feed slices of the document.
 *
 * A product's price row is the customer group's own row when the feed exported
 * one (group-specific catalog rule, group or tier prices), else the fallback
 * row every product carries under group code "0". The final price is the
 * regular price lowered by the best discount or single-quantity tier price,
 * which is the minimum the core BasePrice takes over its price providers.
 *
 * A configurable ranges over the variant documents DocumentHydration attaches:
 * enabled variants pass the stock filter of the core
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
    private const FALLBACK_GROUP_KEY = 'g0';

    public function __construct(
        private readonly Discount $discount,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly TaxConfig $taxConfig,
        private readonly WeeeHelper $weeeHelper,
        private readonly CustomerSession $customerSession,
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

        $groupId = $context->getExtensionAttributes()->getCustomerGroupId()
            ?? $this->customerSession->getCustomerGroupId();
        $groupKey = 'g' . sha1((string)(int)$groupId);

        $range = match ($product->getTypeId()) {
            Type::TYPE_SIMPLE, Type::TYPE_VIRTUAL, DownloadableType::TYPE_DOWNLOADABLE => $this->singleRange($document, $groupKey),
            Configurable::TYPE_CODE => $this->configurableRange(
                $document,
                $product->getData(DocumentHydration::VARIANTS_KEY),
                $groupKey
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
        $row = $this->priceRow($document, $groupKey);
        if ($row === null) {
            return null;
        }
        $regular = (float)$row['regular'];
        $final = $this->finalPrice($row);

        return [$regular, $final, $regular, $final];
    }

    /**
     * @param array[]|null $variants variant documents, null when not attached
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function configurableRange(array $document, ?array $variants, string $groupKey): ?array
    {
        if ($variants === null) {
            return null;
        }
        $candidates = [];
        foreach ($variants as $variant) {
            $row = ($variant['status'] ?? '') === 'Enabled' ? $this->priceRow($variant, $groupKey) : null;
            if ($row === null) {
                continue;
            }
            $candidates[] = [
                'regular' => (float)$row['regular'],
                'final' => $this->finalPrice($row),
                'salable' => $this->salable($variant),
            ];
        }
        $inStock = array_values(array_filter($candidates, static fn(array $candidate) => $candidate['salable']));
        if ($this->stockConfiguration->isShowOutOfStock()) {
            $selected = $this->salable($document) ? ($inStock ?: $candidates) : $candidates;
        } else {
            $selected = $inStock;
        }
        if (!$selected) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        $regular = array_column($selected, 'regular');
        $final = array_column($selected, 'final');

        return [min($regular), min($final), max($regular), max($final)];
    }

    private function priceRow(array $document, string $groupKey): ?array
    {
        $row = $document['prices'][$groupKey] ?? $document['prices'][self::FALLBACK_GROUP_KEY] ?? null;

        return isset($row['regular']) ? $row : null;
    }

    private function finalPrice(array $row): float
    {
        $regular = (float)$row['regular'];
        $final = $regular;
        foreach ((array)($row['discounts'] ?? []) as $discount) {
            $final = min($final, $this->discountedPrice($regular, $discount));
        }
        foreach ((array)($row['tierPrices'] ?? []) as $tier) {
            if ((float)($tier['qty'] ?? 1) <= 1) {
                $final = min($final, $this->discountedPrice($regular, $tier));
            }
        }

        return max(0.0, $final);
    }

    /**
     * @param array{price?: float|null, percentage?: float|null} $discount
     */
    private function discountedPrice(float $regular, array $discount): float
    {
        if (isset($discount['price'])) {
            return (float)$discount['price'];
        }

        return $regular * (1 - (float)($discount['percentage'] ?? 0) / 100);
    }

    private function salable(array $document): bool
    {
        return (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
    }
}
