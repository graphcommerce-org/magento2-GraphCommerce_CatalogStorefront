<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\SwatchesGraphQl\Model\Resolver\Product\Options\SwatchData;

/**
 * Serves swatch_data for option values that ConfigurableOptionsFromDocument
 * built from the document. Values without attached swatch data keep the core
 * resolver.
 */
class SwatchDataFromDocument
{
    public function aroundResolve(
        SwatchData $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!array_key_exists(ConfigurableOptionsFromDocument::SWATCH_KEY, $value ?? [])) {
            return $proceed($field, $context, $info, $value, $args);
        }

        return $value[ConfigurableOptionsFromDocument::SWATCH_KEY];
    }
}
