<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Detail;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\CatalogRule\Observer\ProcessFrontFinalPriceObserver;
use Magento\Customer\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\StoreManagerInterface;

class CatalogRuleFromDocument
{
    public function __construct(
        private readonly ProductPrice $price,
        private readonly Session $session,
        private readonly StoreManagerInterface $stores,
    ) {
    }

    public function aroundExecute(ProcessFrontFinalPriceObserver $subject, \Closure $proceed, Observer $observer)
    {
        $event = $observer->getEvent();
        $product = $event->getProduct();
        $document = $product->getData(ProductDocumentsInterface::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($observer);
        }
        if ($observer->hasDate() || ($observer->hasWebsiteId()
            && (int)$event->getWebsiteId() !== (int)$this->stores->getStore($product->getStoreId())->getWebsiteId())) {
            throw new DocumentReadException('Catalog price rule requires the document date and website.');
        }
        $groupId = $observer->hasCustomerGroupId() ? $event->getCustomerGroupId()
            : ($product->getCustomerGroupId() ?? $this->session->getCustomerGroupId());
        $row = $this->price->row((array)($document['prices'] ?? []), $this->price->groupKey((int)$groupId));
        if ($row === null) {
            throw new DocumentReadException('Catalog price rule requires product price data: ' . $product->getId());
        }
        foreach ((array)($row['discounts'] ?? []) as $discount) {
            if (($discount['code'] ?? '') === 'catalog_rule' && isset($discount['price'])) {
                $product->setFinalPrice(min((float)$product->getData('final_price'), (float)$discount['price']));
            }
        }
        return $subject;
    }
}
