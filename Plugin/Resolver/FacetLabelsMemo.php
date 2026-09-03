<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\AttributeOptionProvider;

/**
 * Remembers the facet labels per process. The core provider runs one join
 * over the attribute and option tables on every listing request; the labels
 * are attribute metadata, which this module keeps per process like the
 * builder's label maps. A changed attribute or option label reaches a worker
 * at its next restart.
 */
class FacetLabelsMemo
{
    private const LIMIT = 500;

    /** @var array<string, array> */
    private array $options = [];

    public function aroundGetOptions(
        AttributeOptionProvider $subject,
        \Closure $proceed,
        array $optionIds,
        ?int $storeId = null,
        array $attributeCodes = []
    ): array {
        sort($optionIds);
        sort($attributeCodes);
        $key = $storeId . ':' . implode(',', $attributeCodes) . ':' . implode(',', $optionIds);
        if (!isset($this->options[$key])) {
            if (count($this->options) >= self::LIMIT) {
                $this->options = [];
            }
            $this->options[$key] = $proceed($optionIds, $storeId, $attributeCodes);
        }

        return $this->options[$key];
    }
}
