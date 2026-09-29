<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGroupedProductFrontend\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\GroupedProduct\ViewModel\ValidateQuantity;
use Magento\Store\Model\StoreManagerInterface;

class QuantityFromDocument
{
    public function __construct(
        private readonly Registry $registry,
        private readonly StoreManagerInterface $stores,
        private readonly StockConfigurationInterface $stockConfig,
        private readonly Session $session,
        private readonly Json $json,
    ) {
    }

    public function aroundGetQuantityValidators(ValidateQuantity $subject, \Closure $proceed, int $productId, ?int $websiteId): string
    {
        $parent = $this->registry->registry('current_product');
        if (!$parent || !is_array($parent->getData(ProductDocumentsInterface::DOCUMENT_KEY))) {
            return $proceed($productId, $websiteId);
        }
        $store = $this->stores->getStore($parent->getStoreId());
        if ($websiteId !== null && $websiteId !== (int)$store->getWebsiteId()) {
            throw new DocumentReadException('Catalog grouped quantity requires the product website.');
        }
        $stock = [];
        foreach ($parent->getTypeInstance()->getAssociatedProducts($parent) as $child) {
            if ((int)$child->getId() === $productId) {
                $stock = $child->getData(ProductDocumentsInterface::DOCUMENT_KEY)['stock'] ?? [];
                break;
            }
        }
        foreach (['minSaleQty', 'maxSaleQty', 'qtyIncrements', 'enableQtyIncrements', 'isQtyDecimal'] as $field) {
            if (!array_key_exists($field, $stock)) {
                throw new DocumentReadException('Catalog grouped quantity requires stock field: ' . $field);
            }
        }
        $storeId = (int)$store->getId();
        $params = ['minAllowed' => (float)($stock['minSaleQty'] ?? $this->stockConfig->getMinSaleQty($storeId, $this->session->getCustomerGroupId()))];
        $max = (float)($stock['maxSaleQty'] ?? $this->stockConfig->getMaxSaleQty($storeId));
        if ($max) {
            $params['maxAllowed'] = $max;
        }
        $enabled = $stock['enableQtyIncrements'] ?? $this->stockConfig->getEnableQtyIncrements($storeId);
        $increment = $enabled ? (float)($stock['qtyIncrements'] ?? $this->stockConfig->getQtyIncrements($storeId)) : 0;
        $increment = $stock['isQtyDecimal'] ? $increment : (int)$increment;
        if ($increment > 0) {
            $params['qtyIncrements'] = $increment;
        }
        return $this->json->serialize(['validate-grouped-qty' => '#super-product-table', 'validate-item-quantity' => $params]);
    }
}
