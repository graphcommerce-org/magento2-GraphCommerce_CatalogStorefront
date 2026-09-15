<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Plugin\DataExporter;

use Magento\CatalogDataExporter\Model\Provider\Product\ProductOptions;
use Magento\ConfigurableProductDataExporter\Model\Provider\Product\ConfigurableOptionValueUid;

/**
 * Keeps the raw configurable entries out of `optionsV2`: the compact
 * `configurableOptions` field carries them, and raw they were a third of a
 * configurable document. Every request-time reader of `optionsV2` looks for
 * the other option types.
 */
class OptionsV2WithoutConfigurable
{
    public function afterGet(ProductOptions $subject, array $output): array
    {
        return array_filter(
            $output,
            static fn(array $row) => ($row['optionsV2']['type'] ?? null) !== ConfigurableOptionValueUid::OPTION_TYPE
        );
    }
}
