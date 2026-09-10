<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model\Read\VariantDocuments;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a configurable's children from documents instead of loading a child collection.
 *
 * This is the expensive call on a listing page. Core builds a full product collection per parent,
 * selecting every listing attribute and then adding media gallery and tier price data — three more
 * queries each. The parent document already lists its variants, so the children are one fetch by id.
 *
 * Falls through to core whenever the parent was not served from a document, when specific
 * attributes are requested, or when any child is missing from the index — a configurable with an
 * incomplete option list is worse than a slow one.
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

        // A filtered request is rare and not on the listing path; core handles it.
        if ($requiredAttributeIds !== null
            || !is_array($product->getData(ProductDocumentsInterface::DOCUMENT_KEY))
        ) {
            return $proceed($product, $requiredAttributeIds);
        }

        $expected = VariantDocuments::childIds(
            (array)$product->getData(ProductDocumentsInterface::DOCUMENT_KEY)
        );
        if ($expected === []) {
            return $proceed($product, $requiredAttributeIds);
        }

        $storeId = (int)$product->getStoreId();

        try {
            $store = $this->storeManager->getStore($storeId);
            $documents = $this->variants->documents($store, $expected)
                ?? $this->products->documents((string)$store->getCode(), $expected);
            $models = $this->products->build($store, $documents);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'catalog-storefront variant fallback: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $proceed($product, $requiredAttributeIds);
        }

        $missing = array_diff($expected, array_keys($models));
        if ($missing !== []) {
            // Say which, and how many were expected: none found points at the query or the store
            // code, a few missing points at the index being behind on those variants.
            $this->logger->info(sprintf(
                'catalog-storefront: product %d loaded its children from the database — '
                . '%d of %d variants have no document (%s)',
                (int)$product->getId(),
                count($missing),
                count($expected),
                implode(', ', array_slice($missing, 0, 10))
            ));

            return $proceed($product, $requiredAttributeIds);
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

    /**
     * Put the price data a card needs onto the child, so computing its price costs no queries.
     *
     * getJsonConfig() calls getPriceInfo() on every child to build optionPrices, and each call
     * otherwise fetches that child's tier prices and catalog rule price — fifteen variants across
     * twelve configurables is 360 queries. A collection-loaded child arrives with both already
     * joined; a document-built one does not.
     *
     * Only what the document states outright is set. Where it is silent the key is left alone and
     * core works it out, because a wrong price is worse than a slow one.
     */
    private function applyPriceData(Product $child, array $document, string $groupKey): void
    {
        $row = $this->productPrice->row((array)($document['prices'] ?? []), $groupKey);
        if ($row === null) {
            return;
        }

        // The exporter tags the rule discount as 'catalog_rule', so it is distinguishable from a
        // special price. Null is a real answer here — "no rule applies" — and setting it still
        // satisfies CatalogRulePrice's hasData() check, which is what skips the query.
        $rulePrice = null;
        foreach ((array)($row['discounts'] ?? []) as $discount) {
            if (($discount['code'] ?? null) === 'catalog_rule') {
                $rulePrice = $discount['price'] ?? null;
                break;
            }
        }
        $child->setData('catalog_rule_price', $rulePrice);

        // Only the empty case. Magento's tier_price structure carries website and customer group
        // fields the feed does not, so a product that genuinely has tier prices is left to core
        // rather than rebuilt from a shape that does not match.
        if (empty($row['tierPrices'])) {
            $child->setData('tier_price', []);
        }
    }
}
