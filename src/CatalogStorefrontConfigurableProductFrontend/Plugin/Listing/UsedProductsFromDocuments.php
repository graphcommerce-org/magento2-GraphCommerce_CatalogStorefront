<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model\Read\VariantDocuments;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a configurable's children from the variant ids in its document.
 * Every variant requires a document and price data.
 */
class UsedProductsFromDocuments
{
    /** Core's own memo key. Setting it keeps anything reading the product's data directly in step. */
    private const CACHE_KEY = '_cache_instance_products';

    public function __construct(
        private readonly ProductDocumentsInterface $products,
        private readonly VariantDocuments $variants,
        private readonly ProductPrice $productPrice,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Configurable $subject
     * @param \Closure $proceed
     * @param Product $product
     * @param array|null $requiredAttributeIds
     * @return Product[]
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetUsedProducts(
        Configurable $subject,
        \Closure $proceed,
        $product,
        $requiredAttributeIds = null
    ) {
        if ($product->hasData(self::CACHE_KEY)) {
            return $product->getData(self::CACHE_KEY);
        }

        if (!is_array($product->getData(ProductDocumentsInterface::DOCUMENT_KEY))) {
            return $proceed($product, $requiredAttributeIds);
        }
        if ($requiredAttributeIds !== null) {
            throw new DocumentReadException('Catalog variants require document support for attribute filters.');
        }

        $expected = VariantDocuments::childIds(
            (array)$product->getData(ProductDocumentsInterface::DOCUMENT_KEY)
        );
        if ($expected === []) {
            throw new DocumentReadException('Catalog configurable document requires variant ids.');
        }

        $storeId = (int)$product->getStoreId();

        try {
            $store = $this->storeManager->getStore($storeId);
            $documents = $this->variants->documents($store, $expected)
                ?? $this->products->documents((string)$store->getCode(), $expected);
            $models = $this->products->build($store, $documents);
        } catch (\Throwable $e) {
            $this->logger->error(
                'catalog-storefront variant document read: ' . $e->getMessage(),
                ['exception' => $e]
            );

            throw new DocumentReadException('Catalog variant documents could not be read.', 0, $e);
        }

        $missing = array_diff($expected, array_keys($models));
        if ($missing !== []) {
            throw new DocumentReadException(sprintf(
                'Catalog product %d requires documents for %d of %d variants (%s).',
                (int)$product->getId(),
                count($missing),
                count($expected),
                implode(', ', array_slice($missing, 0, 10))
            ));
        }

        $groupKey = $this->productPrice->groupKey((int)$this->customerSession->getCustomerGroupId());

        $children = [];
        foreach ($expected as $id) {
            $child = $models[$id];
            $this->applyPriceData($child, $documents[$id], $groupKey);

            $children[] = $child;
        }

        $product->setData(self::CACHE_KEY, $children);

        return $children;
    }

    private function applyPriceData(Product $child, array $document, string $groupKey): void
    {
        $row = $this->productPrice->row((array)($document['prices'] ?? []), $groupKey);
        if ($row === null) {
            throw new DocumentReadException('Catalog variant requires price data: ' . (int)$child->getId());
        }
        if (!empty($row['tierPrices'])) {
            throw new DocumentReadException('Catalog variant requires document support for tier prices: ' . (int)$child->getId());
        }

        // A null catalog_rule_price satisfies CatalogRulePrice's hasData() check.
        $rulePrice = null;
        foreach ((array)($row['discounts'] ?? []) as $discount) {
            if (($discount['code'] ?? null) === 'catalog_rule') {
                $rulePrice = $discount['price'] ?? null;
                break;
            }
        }
        $child->setData('catalog_rule_price', $rulePrice);

        $child->setData('tier_price', []);
    }
}
