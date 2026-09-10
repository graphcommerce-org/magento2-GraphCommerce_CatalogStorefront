<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Layer;

use GraphCommerce\CatalogStorefront\Model\Read\ProductAttributeOptions;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\AttributeOptionProvider;

/**
 * Product-scoped, deterministically ordered replacement for the core provider.
 *
 * The core query has no EAV entity-type condition and groups its result by
 * attribute code. An option from another entity with the same code can then
 * become product facet metadata. Its sort_order-only ordering also leaves ties
 * dependent on the database plan. The shared read fixes both without changing
 * the provider's public contract or bypassing its plugin chain.
 */
class ProductAttributeOptionProvider extends AttributeOptionProvider
{
    public function __construct(
        private readonly ProductAttributeOptions $productAttributeOptions,
    ) {
    }

    public function getOptions(array $optionIds, ?int $storeId, array $attributeCodes = []): array
    {
        return $this->productAttributeOptions->matching($optionIds, $storeId, $attributeCodes);
    }
}
