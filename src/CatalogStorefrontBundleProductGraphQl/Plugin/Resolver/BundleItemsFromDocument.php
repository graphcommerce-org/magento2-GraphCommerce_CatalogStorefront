<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProductGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\BundleGraphQl\Model\Resolver\BundleItems;
use Magento\Catalog\Model\Product\Type;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Serves a bundle's items from the option slice of its document and the
 * documents of its selections. Each item carries its options pre-filled, each
 * option its label, so the core link and label resolvers do not run; the
 * option's product is the selection's model, which the core product resolver
 * takes as is. A selection whose child is not salable is left out unless the
 * store shows out of stock products, as core's selection collection does. An
 * item's price range is the bundle's own.
 */
class BundleItemsFromDocument
{
    private const PRICE_TYPES = ['fixed' => 'FIXED', 'percent' => 'PERCENT'];

    public function __construct(
        private readonly HydrationInterface $hydration,
        private readonly Uid $uidEncoder,
        private readonly Strict $strict,
        private readonly StockConfigurationInterface $stockConfiguration,
    ) {
    }

    public function aroundResolve(
        BundleItems $subject,
        \Closure $proceed,
        Field $field,
        ContextInterface $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(HydrationInterface::DOCUMENT_KEY);
        if (!is_array($document) || ($value['type_id'] ?? null) !== Type::TYPE_BUNDLE) {
            return $proceed($field, $context, $info, $value, $args);
        }

        try {
            $store = $context->getExtensionAttributes()->getStore();
            $requestedFields = array_keys((array)($info->getFieldSelection(2)['options']['product'] ?? []));
            $bySku = [];
            $children = $this->hydration->documents($store->getCode(), (array)($document['bundleChildIds'] ?? []));
            $showOutOfStock = $this->stockConfiguration->isShowOutOfStock((int)$store->getId());
            $hidden = array_column(array_filter(
                $children,
                static fn(array $child) => !$showOutOfStock && !($child['stock']['isSalable'] ?? $child['inStock'] ?? false)
            ), 'sku', 'sku');
            foreach ($this->hydration->models($store, $context, $children, $requestedFields) as $model) {
                $bySku[$model->getSku()] = $model;
            }

            $items = [];
            $parentId = (int)$document['productId'];
            $parentRange = $value['model']->getData(PrefillerInterface::KEY)['price_range'] ?? null;
            if ($parentRange === null && isset($info->getFieldSelection(0)['price_range'])) {
                $this->hydration->prefill($store, $context, [$parentId => $value['model']], [$parentId => $document], ['price_range']);
                $parentRange = $value['model']->getData(PrefillerInterface::KEY)['price_range'] ?? null;
            }
            foreach ((array)($document['optionsV2'] ?? []) as $option) {
                if (($option['type'] ?? null) !== 'bundle') {
                    continue;
                }
                $links = [];
                $values = (array)($option['values'] ?? []);
                usort($values, static fn(array $a, array $b) => (int)($a['sortOrder'] ?? 0) <=> (int)($b['sortOrder'] ?? 0));
                foreach ($values as $selection) {
                    if (isset($hidden[$selection['sku'] ?? ''])) {
                        continue;
                    }
                    [, $optionId, $selectionId, $selectionQty] = array_pad(
                        explode('/', $this->uidEncoder->decode((string)$selection['id'])),
                        4,
                        null
                    );
                    $model = $bySku[$selection['sku'] ?? ''] ?? null;
                    $links[] = [
                        'option_id' => (int)$optionId,
                        'selection_id' => (int)$selectionId,
                        'selection_qty' => $selectionQty,
                        'parent_id' => $parentId,
                        'sku' => $selection['sku'] ?? null,
                        'id' => (int)$selectionId,
                        'uid' => (string)$selection['id'],
                        'qty' => (float)($selection['qty'] ?? 0),
                        'quantity' => (float)($selection['qty'] ?? 0),
                        'position' => (int)($selection['sortOrder'] ?? 0),
                        'is_default' => (bool)($selection['isDefault'] ?? false),
                        'price' => (float)($selection['price'] ?? 0),
                        'price_type' => self::PRICE_TYPES[$selection['priceType'] ?? ''] ?? 'DYNAMIC',
                        'can_change_quantity' => (bool)($selection['qtyMutability'] ?? false),
                        'product' => $model ? ['model' => $model, 'sku' => $model->getSku()] : [],
                        PrefillerInterface::KEY => ['label' => $model?->getName() ?? $selection['label'] ?? null],
                    ];
                }
                $items[] = [
                    'option_id' => (int)$option['id'],
                    'uid' => $this->uidEncoder->encode('bundle/' . $option['id']),
                    'title' => $option['label'] ?? null,
                    'required' => (bool)($option['required'] ?? false),
                    'type' => $option['renderType'] ?? null,
                    'position' => (int)($option['sortOrder'] ?? 0),
                    'sku' => $document['sku'],
                    'parent_id' => $parentId,
                    PrefillerInterface::KEY => ['options' => $links] + ($parentRange === null ? [] : ['price_range' => $parentRange]),
                ];
            }
            usort($items, static fn(array $a, array $b) => [$a['position'], $a['option_id']] <=> [$b['position'], $b['option_id']]);
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }

        return $items;
    }
}
