<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type;
use Magento\CatalogGraphQl\Model\Resolver\Product\Price\Discount;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Review\Model\Review\Config as ReviewsConfig;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Weee\Helper\Data as WeeeHelper;

/**
 * Fills, on the product value the executor hands to child fields, the fields
 * whose core resolvers only derive from the model and the document, so the
 * executor returns them as plain values instead of running a resolver call
 * per product. ReuseSchema routes a listed field to this key and to the core
 * resolver when the key is absent, which keeps the core path for core-served
 * products and for what a document cannot answer. Only fields the query
 * selects are filled.
 */
class Prefill
{
    public const KEY = '_gc_prefilled';

    private const IMAGE_TYPES = ['image', 'small_image', 'thumbnail'];

    private const DATE_ATTRIBUTES = ['new_from_date' => 'news_from_date', 'new_to_date' => 'news_to_date'];

    public function __construct(
        private readonly Uid $uidEncoder,
        private readonly ImageUrl $imageUrl,
        private readonly ReviewsConfig $reviewsConfig,
        private readonly Discount $discount,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly TaxConfig $taxConfig,
        private readonly WeeeHelper $weeeHelper,
        private readonly ProductPrice $productPrice,
        private readonly BundlePriceRange $bundlePriceRange,
        private readonly RatingMetadata $ratingMetadata,
    ) {
    }

    /**
     * @param Product[] $models keyed by product id
     * @param array[] $documents keyed by product id
     * @param array $priceData composite price data as ProductDocumentStorage::priceData() returns it, or empty
     * @param string[] $requestedFields product fields the query selects; empty selects all
     */
    public function fill(
        array $models,
        array $documents,
        StoreInterface $store,
        string $groupKey,
        array $priceData,
        array $requestedFields
    ): void {
        $wanted = $requestedFields ? array_flip($requestedFields) : null;
        $selected = static fn(string $field): bool => $wanted === null || isset($wanted[$field]);
        $reviewsEnabled = ($selected('rating_summary') || $selected('review_count')) && $this->reviewsConfig->isEnabled();
        $priceServable = $selected('price_range')
            && $store->getCurrentCurrencyCode() === $store->getBaseCurrencyCode()
            && !$this->taxConfig->priceIncludesTax($store)
            && (int)$this->taxConfig->getPriceDisplayType($store) === TaxConfig::DISPLAY_TYPE_EXCLUDING_TAX
            && !$this->weeeHelper->isEnabled($store);
        $currency = $store->getCurrentCurrencyCode();
        $showOutOfStock = $priceServable && $this->stockConfiguration->isShowOutOfStock();

        foreach ($models as $id => $product) {
            $document = $documents[$id] ?? [];
            $filled = [];
            if ($selected('uid')) {
                $filled['uid'] = $this->uidEncoder->encode((string)$id);
            }
            if ($selected('id')) {
                $filled['id'] = (int)$id;
            }
            // The products query hands the field over translated to its attribute code.
            foreach (self::DATE_ATTRIBUTES as $field => $attribute) {
                if ($selected($field) || $selected($attribute)) {
                    $filled[$field] = $product->getData($attribute) ?: null;
                }
            }
            if ($selected('rating_summary') || $selected('review_count')) {
                $reviews = $reviewsEnabled ? array_filter((array)($document['reviews'] ?? [])) : [];
                $percents = [];
                foreach ($reviews as $votes) {
                    foreach ((array)$votes as $ratingId => $value) {
                        $scale = $this->ratingMetadata->scale($store->getCode(), (int)$ratingId);
                        if ($scale) {
                            $percents[] = (int)$value / $scale * 100;
                        }
                    }
                }
                $filled['rating_summary'] = $percents ? (float)round(array_sum($percents) / count($percents)) : 0.0;
                $filled['review_count'] = count($reviews);
            }
            foreach (self::IMAGE_TYPES as $type) {
                if ($selected($type)) {
                    $file = $product->getData($type);
                    $filled[$type] = [
                        self::KEY => ['url' => $this->imageUrl->get($store, $type, is_string($file) ? $file : null)],
                        'label' => $product->getData($type . '_label') ?: $product->getData('name'),
                    ];
                }
            }
            if ($priceServable) {
                $range = match ($product->getTypeId()) {
                    Type::TYPE_SIMPLE, Type::TYPE_VIRTUAL, DownloadableType::TYPE_DOWNLOADABLE => $this->singleRange($document, $groupKey),
                    Configurable::TYPE_CODE => $this->configurableRange($document, $priceData['configurable'][$id] ?? null, $showOutOfStock),
                    Grouped::TYPE_CODE => $this->groupedRange($priceData['grouped'][$id] ?? null, $showOutOfStock),
                    Type::TYPE_BUNDLE => $this->bundlePriceRange->range(
                        $document + ($priceData['bundleOptions'][$id] ?? []),
                        $priceData['bundle'][$id] ?? [],
                        $groupKey,
                        $showOutOfStock
                    ),
                    default => null,
                };
                if ($range !== null) {
                    $filled['price_range'] = $this->priceRange($product, $document, $range, $currency);
                }
            }
            $product->setData(self::KEY, $filled);
        }
    }

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

    private function configurableRange(array $document, ?array $ranges, bool $showOutOfStock): ?array
    {
        if ($ranges === null) {
            return null;
        }
        $parentSalable = (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
        $range = $showOutOfStock
            ? ($parentSalable ? ($ranges['salable'] ?? $ranges['all']) : $ranges['all'])
            : $ranges['salable'];

        return $range ?? [0.0, 0.0, 0.0, 0.0];
    }

    /**
     * Core takes, over the associated products, the lowest regular and the
     * lowest final price each on its own, and the maximum equals the minimum;
     * out-of-stock children count only when out-of-stock products are shown.
     */
    private function groupedRange(?array $ranges, bool $showOutOfStock): ?array
    {
        $range = $ranges === null ? null : ($showOutOfStock ? $ranges['all'] : $ranges['salable']);

        return $range === null ? null : [$range[0], $range[1], $range[0], $range[1]];
    }

    private function priceRange(Product $product, array $document, array $range, string $currency): array
    {
        [$minRegular, $minFinal, $maxRegular, $maxFinal] = $range;
        $format = fn(float $regular, float $final): array => [
            'regular_price' => ['value' => $this->priceCurrency->roundPrice($regular), 'currency' => $currency],
            'final_price' => ['value' => $this->priceCurrency->roundPrice($final), 'currency' => $currency],
            'discount' => $this->discount->getDiscountByDifference($regular, $final),
            'model' => $product,
        ];
        $result = [
            'minimum_price' => $format($minRegular, $minFinal),
            'maximum_price' => $format($maxRegular, $maxFinal),
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
}
