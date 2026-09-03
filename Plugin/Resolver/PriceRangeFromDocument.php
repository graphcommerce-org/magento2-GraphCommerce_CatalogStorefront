<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\CatalogGraphQl\Model\PriceRangeDataProvider;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Serves price_range from the price feed for single-price product types, where
 * the minimum and maximum prices are the same. Types whose range spans children
 * or options (configurable, bundle, grouped, downloadable) fall through to the
 * core computation.
 */
class PriceRangeFromDocument
{
    private const GUEST_CUSTOMER_GROUP = '0';
    private const SINGLE_PRICE_TYPES = ['simple' => true, 'virtual' => true];
    private const ZERO_THRESHOLD = 0.000001;

    public function aroundPrepare(
        PriceRangeDataProvider $subject,
        \Closure $proceed,
        ContextInterface $context,
        ResolveInfo $info,
        array $value
    ): array {
        $product = $value['model'] ?? null;
        $document = $product?->getData(ProductModelBuilder::DOCUMENT_KEY);
        if (!is_array($document) || !isset(self::SINGLE_PRICE_TYPES[$document['type'] ?? ''])) {
            return $proceed($context, $info, $value);
        }

        $priceRow = $this->guestPrice($document);
        if ($priceRow === null) {
            return $proceed($context, $info, $value);
        }

        $currency = (string)$context->getExtensionAttributes()->getStore()->getCurrentCurrencyCode();
        $regular = (float)$priceRow['regular'];
        $final = $this->finalPrice($priceRow, $regular);
        $price = $this->formatPrice($regular, $final, $currency);

        return ['minimum_price' => $price, 'maximum_price' => $price];
    }

    private function guestPrice(array $document): ?array
    {
        foreach ((array)($document['prices'] ?? []) as $row) {
            if (($row['customerGroupCode'] ?? null) === self::GUEST_CUSTOMER_GROUP && isset($row['regular'])) {
                return $row;
            }
        }

        return null;
    }

    private function finalPrice(array $priceRow, float $regular): float
    {
        $final = $regular;
        foreach ($priceRow['discounts'] ?? [] as $discount) {
            if (isset($discount['price'])) {
                $final = min($final, (float)$discount['price']);
            }
        }

        return $final;
    }

    private function formatPrice(float $regular, float $final, string $currency): array
    {
        $difference = $regular - $final;
        $amountOff = $difference <= self::ZERO_THRESHOLD ? 0.0 : round($difference, 2);
        $percentOff = ($difference <= self::ZERO_THRESHOLD || $regular <= self::ZERO_THRESHOLD)
            ? 0.0
            : round(($difference / $regular) * 100, 2);

        return [
            'regular_price' => ['value' => round($regular, 2), 'currency' => $currency],
            'final_price' => ['value' => round($final, 2), 'currency' => $currency],
            'discount' => ['amount_off' => $amountOff, 'percent_off' => $percentOff],
        ];
    }
}
