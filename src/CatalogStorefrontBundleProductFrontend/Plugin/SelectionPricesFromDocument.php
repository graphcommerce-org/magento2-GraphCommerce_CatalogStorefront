<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontBundleProductFrontend\Plugin;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use Magento\Bundle\Pricing\Adjustment\SelectionPriceListProviderInterface;
use Magento\Bundle\Pricing\Price\BundleSelectionFactory;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;

class SelectionPricesFromDocument
{
    public function __construct(private readonly BundleSelectionFactory $prices, private readonly StockConfigurationInterface $stockConfig)
    {
    }

    public function aroundGetPriceList(SelectionPriceListProviderInterface $subject, \Closure $proceed, Product $bundleProduct, $searchMin, $useRegularPrice): array
    {
        if (!is_array($bundleProduct->getData(ProductDocumentsInterface::DOCUMENT_KEY))) {
            return $proceed($bundleProduct, $searchMin, $useRegularPrice);
        }
        $type = $bundleProduct->getTypeInstance();
        $options = $type->getOptionsCollection($bundleProduct)->getItems();
        $fixed = (int)$bundleProduct->getPriceType() === 1;
        $hasRequired = (bool)array_filter($options, static fn($option) => (bool)$option->getRequired());
        $lowestOption = $searchMin && !$fixed && !$hasRequired;
        $prices = [];
        foreach ($options as $option) {
            if ($searchMin && !$lowestOption && !$option->getRequired()) {
                continue;
            }
            $all = !$searchMin && $option->isMultiSelection();
            $candidates = [];
            foreach ($type->getSelectionsCollection([$option->getId()], $bundleProduct) as $selection) {
                if ((int)$selection->getStatus() !== 1) {
                    continue;
                }
                $storeId = $bundleProduct->getStoreId();
                if (!$this->stockConfig->isShowOutOfStock($storeId) && !$selection->isSalable()) {
                    continue;
                }
                if (!$all && $bundleProduct->isSalable()) {
                    $stock = $selection->getData(ProductDocumentsInterface::DOCUMENT_KEY)['stock'] ?? [];
                    foreach (['manageStock', 'backorders', 'minSaleQty', 'itemInStock', 'itemQty'] as $field) {
                        if (!array_key_exists($field, $stock)) {
                            throw new DocumentReadException('Catalog bundle price requires stock field: ' . $field);
                        }
                    }
                    $manage = $stock['manageStock'] ?? $this->stockConfig->getManageStock($storeId);
                    $backorders = $stock['backorders'] ?? $this->stockConfig->getBackorders($storeId);
                    $qty = $selection->getSelectionCanChangeQty()
                        ? ($stock['minSaleQty'] ?? $this->stockConfig->getMinSaleQty($storeId))
                        : $selection->getSelectionQty();
                    if ($manage && (!$stock['itemInStock'] || (!$backorders && $qty > $stock['itemQty']))) {
                        continue;
                    }
                }
                $price = $this->prices->create($bundleProduct, $selection, $selection->getSelectionQty(), ['useRegularPrice' => $useRegularPrice]);
                $sortPrice = $fixed
                    ? ($selection->getSelectionPriceType() ? $bundleProduct->getPrice() * $selection->getSelectionPriceValue() / 100 : $selection->getSelectionPriceValue())
                    : ($useRegularPrice ? $selection->getPrice() : $selection->getData('minimal_price'));
                $candidates[] = ['price' => $price, 'sort' => $sortPrice * $selection->getSelectionQty()];
            }
            if (!$all && $candidates) {
                usort($candidates, static fn($a, $b) => $searchMin ? $a['sort'] <=> $b['sort'] : $b['sort'] <=> $a['sort']);
                $candidates = [$candidates[0]];
            }
            array_push($prices, ...array_column($candidates, 'price'));
        }
        if ($lowestOption && $prices) {
            usort($prices, static fn($a, $b) => ($a->getAmount()->getValue() * $a->getQuantity()) <=> ($b->getAmount()->getValue() * $b->getQuantity()));
            return [$prices[0]];
        }
        return $prices;
    }
}
