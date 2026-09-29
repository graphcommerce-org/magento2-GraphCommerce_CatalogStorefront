<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWishlistGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\ItemProducts;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\WishlistGraphQl\Model\Resolver\ProductResolver;

/**
 * Serves a wish list item's product from the document of the list's batch.
 * The core resolver loads one product per item through the repository.
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
    ) {
        if (!$this->itemProducts->enabled()) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $productId = (int)(($value['model'] ?? null)?->getId());
        $product = $productId ? $this->itemProducts->value($productId) : null;
        if ($product === null) {
            $this->strict->fallback(self::class, 'no document for product ' . $productId);
        }

        return $product;
    }
}
