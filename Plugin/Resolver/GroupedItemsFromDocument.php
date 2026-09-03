<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\DocumentHydration;
use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\GroupedProductGraphQl\Model\Resolver\GroupedItems;
use Psr\Log\LoggerInterface;

/**
 * Serves a grouped product's items from the option slice of its document and
 * the documents of its enabled associated products, in link position order;
 * the item's product is the associated product's model, which the core
 * product resolver takes as is.
 */
class GroupedItemsFromDocument
{
    public function __construct(
        private readonly DocumentHydration $hydration,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function aroundResolve(
        GroupedItems $subject,
        \Closure $proceed,
        Field $field,
        ContextInterface $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(ProductModelBuilder::DOCUMENT_KEY);
        if (!is_array($document) || ($value['type_id'] ?? null) !== Grouped::TYPE_CODE) {
            return $proceed($field, $context, $info, $value, $args);
        }

        try {
            $store = $context->getExtensionAttributes()->getStore();
            $requestedFields = array_keys((array)($info->getFieldSelection(1)['product'] ?? []));
            $bySku = [];
            $children = array_filter(
                $this->hydration->documents($store->getCode(), (array)($document['groupedChildIds'] ?? [])),
                static fn(array $child) => ($child['status'] ?? null) === 'Enabled'
            );
            foreach ($this->hydration->models($store, $context, $children, $requestedFields) as $model) {
                $bySku[$model->getSku()] = $model;
            }

            $items = [];
            foreach ((array)($document['optionsV2'] ?? []) as $option) {
                if (($option['type'] ?? null) !== Grouped::TYPE_CODE) {
                    continue;
                }
                foreach ((array)($option['values'] ?? []) as $link) {
                    $model = $bySku[$link['sku'] ?? ''] ?? null;
                    if ($model === null) {
                        continue;
                    }
                    $items[] = [
                        'position' => (int)($link['sortOrder'] ?? 0),
                        'qty' => (float)($link['qty'] ?? 0),
                        'sku' => $model->getSku(),
                        'product' => ['model' => $model, 'sku' => $model->getSku()],
                    ];
                }
            }
            usort($items, static fn(array $a, array $b) => [$a['position'], $a['sku']] <=> [$b['position'], $b['sku']]);
        } catch (\Throwable $e) {
            $this->logger->warning('catalog-storefront grouped items fallback: ' . $e->getMessage());

            return $proceed($field, $context, $info, $value, $args);
        }

        return $items;
    }
}
