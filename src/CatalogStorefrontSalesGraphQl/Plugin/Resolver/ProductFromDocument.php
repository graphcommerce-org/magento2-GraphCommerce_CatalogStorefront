<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSalesGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\ItemProducts;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\SalesGraphQl\Model\Resolver\ProductResolver;

/**
 * Serves an order item's product from the document of the order's batch. The
 * core resolver loads one product per item through the repository.
 */
class ProductFromDocument
{
    public function __construct(
        private readonly ItemProducts $itemProducts,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        ProductResolver $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): ?array {
        $orderItem = $value['model'] ?? null;
        if (!$this->itemProducts->enabled() || !$orderItem instanceof OrderItemInterface) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $productId = (int)$orderItem->getProductId();
        $product = $this->itemProducts->value($productId);
        if ($product === null) {
            $this->strict->fallback(self::class, 'no document for product ' . $productId);

            return $proceed($field, $context, $info, $value, $args);
        }

        return $product;
    }
}
