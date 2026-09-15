<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWishlistGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Query\SelectedProductFields;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\ItemProducts;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Registers the products of the wish list items the resolver returns, so the
 * item product resolver serves them from documents fetched in one request.
 */
class WishlistItemProducts
{
    public function __construct(
        private readonly ItemProducts $itemProducts,
        private readonly SelectedProductFields $selectedFields,
    ) {
    }

    public function afterResolve(
        ResolverInterface $subject,
        $result,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->itemProducts->enabled() || !is_array($result)) {
            return $result;
        }
        $ids = [];
        foreach ($result['items'] ?? $result as $item) {
            $ids[] = (int)(is_array($item) ? ($item['model'] ?? null)?->getId() : 0);
        }
        $this->itemProducts->expect($ids, $this->selectedFields->of($info), $context);

        return $result;
    }
}
