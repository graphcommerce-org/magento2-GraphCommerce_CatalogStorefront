<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventoryGraphQl\Model\Resolver\MaxSaleQtyResolver;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Serves max_sale_qty from stock configuration for document-served products.
 *
 * The core resolver loads a stock item per product, and reloads the whole
 * product through the repository for configurables, which is costly in a
 * listing. The product feed carries no per-product max_sale_qty override, so
 * this returns the store-level configured value. A per-product override would
 * need the inventory feed extended to carry it.
 */
class MaxSaleQtyFromConfig
{
    public function __construct(
        private readonly StockConfigurationInterface $stockConfiguration,
    ) {
    }

    public function aroundResolve(
        MaxSaleQtyResolver $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $product = $value['model'] ?? null;
        if (!is_array($product?->getData(ProductModelBuilder::DOCUMENT_KEY))) {
            return $proceed($field, $context, $info, $value, $args);
        }

        return (float)$this->stockConfiguration->getMaxSaleQty();
    }
}
