<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontPrice\Model\Read\TaxPrice;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The taxed or untaxed price of a document product's amount from the rate of the request,
 * as the GraphQL path prices it, instead of core's quote details and tax calculator per
 * amount. A call with its own addresses, customer tax class or price-includes-tax flag,
 * or without an explicit including-tax flag, takes core's calculation.
 */
class TaxPriceFromRate
{
    public function __construct(
        private readonly TaxPrice $taxPrice,
        private readonly StoreManagerInterface $storeManager,
        private readonly PriceCurrencyInterface $priceCurrency,
    ) {
    }

    /**
     * @param mixed $product
     * @param mixed $price
     * @param mixed $includingTax
     * @param mixed $shippingAddress
     * @param mixed $billingAddress
     * @param mixed $ctc
     * @param mixed $store
     * @param mixed $priceIncludesTax
     * @param mixed $roundPrice
     * @return mixed
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function aroundGetTaxPrice(
        CatalogHelper $subject,
        \Closure $proceed,
        $product,
        $price,
        $includingTax = null,
        $shippingAddress = null,
        $billingAddress = null,
        $ctc = null,
        $store = null,
        $priceIncludesTax = null,
        $roundPrice = true
    ) {
        if (!is_bool($includingTax)
            || $shippingAddress !== null
            || $billingAddress !== null
            || $ctc !== null
            || $priceIncludesTax !== null
            || !$product instanceof Product
            || !is_array($product->getData(ProductDocumentsInterface::DOCUMENT_KEY))
        ) {
            return $proceed($product, $price, $includingTax, $shippingAddress, $billingAddress, $ctc, $store, $priceIncludesTax, $roundPrice);
        }
        if (!$price) {
            return $price;
        }
        $storeModel = $this->storeManager->getStore($store);
        $value = $this->taxPrice->of((float)$price, $includingTax, $product, $storeModel);

        return $roundPrice ? $this->priceCurrency->round($value) : $value;
    }
}
