<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSalesGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQl\Model\Query\SelectedProductFields;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\ItemProducts;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\SalesGraphQl\Model\Resolver\OrderItems;

/**
 * Registers the products of the order's items, so the item product resolver
 * serves them from documents fetched in one request. The resolver answers
 * with deferred values, and the order carries every item already.
 */
class OrderItemProducts
{
    public function __construct(
        private readonly ItemProducts $itemProducts,
        private readonly SelectedProductFields $selectedFields,
    ) {
    }

    public function afterResolve(
        OrderItems $subject,
        $result,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $order = $value['model'] ?? null;
        if (!$this->itemProducts->enabled() || !$order instanceof OrderInterface) {
            return $result;
        }
        $ids = [];
        foreach ($order->getItems() as $item) {
            $ids[] = (int)$item->getProductId();
        }
        $this->itemProducts->expect($ids, $this->selectedFields->of($info), $context);

        return $result;
    }
}
