<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProductFrontend\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read\OptionsFactory;
use GraphCommerce\CatalogStorefrontBundleProductFrontend\Model\Read\SelectionsFactory;
use Magento\Bundle\Model\OptionFactory;
use Magento\Bundle\Model\Product\Type;
use Magento\Catalog\Model\Product;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Store\Model\StoreManagerInterface;

class OptionsFromDocument
{
    private const CHILDREN = '_gc_bundle_children';
    private const OPTIONS = '_cache_instance_options_collection';

    public function __construct(
        private readonly ProductDocumentsInterface $products,
        private readonly StoreManagerInterface $stores,
        private readonly OptionFactory $optionFactory,
        private readonly OptionsFactory $optionsFactory,
        private readonly SelectionsFactory $selectionsFactory,
        private readonly Uid $uid,
    ) {
    }

    public function aroundGetOptionsCollection(Type $subject, \Closure $proceed, Product $product)
    {
        $document = $product->getData(ProductDocumentsInterface::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($product);
        }
        if ($product->hasData(self::OPTIONS)) {
            return $product->getData(self::OPTIONS);
        }
        $options = (array)($document['optionsV2'] ?? []);
        usort($options, static fn(array $a, array $b) => [(int)$a['sortOrder'], (int)$a['id']] <=> [(int)$b['sortOrder'], (int)$b['id']]);
        $collection = $this->optionsFactory->create();
        foreach ($options as $option) {
            if (($option['type'] ?? '') !== 'bundle') {
                throw new DocumentReadException('Catalog bundle requires document support for option type: ' . ($option['type'] ?? 'unknown'));
            }
            $collection->addItem($this->optionFactory->create()->setData([
                'option_id' => (string)$option['id'],
                'parent_id' => (string)$product->getId(),
                'title' => $option['label'],
                'default_title' => $option['label'],
                'type' => $option['renderType'],
                'required' => (string)(int)$option['required'],
                'position' => (string)$option['sortOrder'],
            ]));
        }
        $product->setData(self::OPTIONS, $collection);
        return $collection;
    }

    public function aroundGetSelectionsCollection(Type $subject, \Closure $proceed, $optionIds, Product $product)
    {
        $document = $product->getData(ProductDocumentsInterface::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($optionIds, $product);
        }
        if (!$product->hasData(self::CHILDREN)) {
            $store = $this->stores->getStore($product->getStoreId());
            $ids = array_map('intval', (array)($document['bundleChildIds'] ?? []));
            $documents = $this->products->documents($store->getCode(), $ids);
            $models = $this->products->build($store, $documents);
            if (array_diff($ids, array_keys($models))) {
                throw new DocumentReadException('Catalog bundle requires all child documents: ' . $product->getId());
            }
            $children = [];
            foreach ($models as $model) {
                $children[$model->getSku()] = $model;
            }
            $product->setData(self::CHILDREN, $children);
        }
        $children = $product->getData(self::CHILDREN);
        $selections = [];
        foreach ((array)($document['optionsV2'] ?? []) as $option) {
            if (($option['type'] ?? '') !== 'bundle' || ($optionIds && !in_array($option['id'], $optionIds))) {
                continue;
            }
            foreach ((array)($option['values'] ?? []) as $value) {
                $child = $children[$value['sku']] ?? throw new DocumentReadException('Catalog bundle child document is missing: ' . $value['sku']);
                if ($child->getRequiredOptions()) {
                    continue;
                }
                $parts = explode('/', $this->uid->decode((string)$value['id']));
                if (count($parts) !== 4 || $parts[0] !== 'bundle' || (int)$parts[1] !== (int)$option['id']
                    || !ctype_digit($parts[2]) || (int)$parts[2] <= 0) {
                    throw new DocumentReadException('Catalog bundle selection UID is invalid: ' . $value['id']);
                }
                $selection = clone $child;
                $selection->addData([
                    'selection_id' => $parts[2],
                    'option_id' => (string)$option['id'],
                    'parent_product_id' => (string)$product->getId(),
                    'position' => (string)$value['sortOrder'],
                    'is_default' => (string)(int)$value['isDefault'],
                    'selection_qty' => (float)$value['qty'],
                    'selection_can_change_qty' => (string)(int)$value['qtyMutability'],
                    'selection_price_type' => ($value['priceType'] ?? '') === 'percent' ? '1' : '0',
                    'selection_price_value' => (float)($value['price'] ?? 0),
                ]);
                $selections[] = $selection;
            }
        }
        usort($selections, static fn(Product $a, Product $b) => [(int)$a->getData('position'), (int)$a->getData('selection_id')] <=> [(int)$b->getData('position'), (int)$b->getData('selection_id')]);
        $collection = $this->selectionsFactory->create();
        $collection->setStoreId($product->getStoreId());
        $collection->setFlag('catalog_rule_loaded', true)->setFlag('tier_price_added', true);
        foreach ($selections as $selection) {
            $collection->addItem($selection);
        }
        return $collection;
    }
}
