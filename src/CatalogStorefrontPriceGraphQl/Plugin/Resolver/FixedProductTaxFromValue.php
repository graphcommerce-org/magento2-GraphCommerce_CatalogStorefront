<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPriceGraphQl\Plugin\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\WeeeGraphQl\Model\Resolver\FixedProductTax;

/**
 * Serves fixed_product_taxes as the prices prefiller put them on the price
 * value; core's resolver reads the weee table per product otherwise.
 */
class FixedProductTaxFromValue
{
    public function aroundResolve(
        FixedProductTax $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        return $value['fixed_product_taxes'] ?? $proceed($field, $context, $info, $value, $args);
    }
}
