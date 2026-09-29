<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGroupedProductFrontend\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Store\Model\StoreManagerInterface;

class AssociatedProductsFromDocument
{
    private const CACHE = '_cache_instance_associated_products';

    public function __construct(
        private readonly ProductDocumentsInterface $products,
        private readonly StoreManagerInterface $stores,
        private readonly StockConfigurationInterface $stockConfig,
    ) {
    }

    public function aroundGetAssociatedProducts(Grouped $subject, \Closure $proceed, Product $product): array
    {
        $document = $product->getData(ProductDocumentsInterface::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($product);
        }
        if ($product->hasData(self::CACHE)) {
            return $product->getData(self::CACHE);
        }
        $store = $this->stores->getStore($product->getStoreId());
        $ids = array_map('intval', (array)($document['groupedChildIds'] ?? []));
        $documents = $this->products->documents($store->getCode(), $ids);
        $models = $this->products->build($store, $documents);
        if (array_diff($ids, array_keys($models))) {
            throw new DocumentReadException('Catalog grouped product requires all child documents: ' . $product->getId());
        }
        $bySku = [];
        foreach ($models as $model) {
            $bySku[$model->getSku()] = $model;
        }
        $associated = [];
        foreach ((array)($document['optionsV2'] ?? []) as $option) {
            if (($option['type'] ?? '') !== 'grouped') {
                continue;
            }
            foreach ((array)($option['values'] ?? []) as $link) {
                $child = $bySku[$link['sku']] ?? throw new DocumentReadException('Catalog grouped child document is missing: ' . $link['sku']);
                if ((int)$child->getStatus() !== 1 || $child->getRequiredOptions()
                    || (!$this->stockConfig->isShowOutOfStock($product->getStoreId()) && !$child->isSalable())) {
                    continue;
                }
                $child = clone $child;
                $child->setData('qty', (float)$link['qty']);
                $child->setData('position', (int)$link['sortOrder']);
                $associated[] = $child;
            }
        }
        usort($associated, static fn(Product $a, Product $b) => [$a->getData('position'), (int)$a->getId()] <=> [$b->getData('position'), (int)$b->getId()]);
        $product->setData(self::CACHE, $associated);
        return $associated;
    }
}
