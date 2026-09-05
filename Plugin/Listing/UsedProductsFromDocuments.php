<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Listing;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use GraphCommerce\CatalogStorefront\Model\Read\VariantRepository;
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
 * queries each. The variants feed already records the relationship on both sides, so the children
 * can be assembled from documents the page has largely fetched already.
 *
 * Falls through to core whenever the parent was not served from a document, when specific
 * attributes are requested, or when any child is missing from the index — a configurable with an
 * incomplete option list is worse than a slow one.
 */
class UsedProductsFromDocuments
{
    /**
     * Core's own memo key. Setting it keeps anything reading the product's data directly in step.
     */
    private const CACHE_KEY = '_cache_instance_products';

    /**
     * @param VariantRepository $variants
     * @param ProductModelBuilder $modelBuilder
     * @param ProductPrice $productPrice
     * @param StoreManagerInterface $storeManager
     * @param CustomerSession $customerSession
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly VariantRepository $variants,
        private readonly ProductModelBuilder $modelBuilder,
        private readonly ProductPrice $productPrice,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly LoggerInterface $logger
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
        if ($requiredAttributeIds !== null || !is_array($product->getData(ProductModelBuilder::DOCUMENT_KEY))) {
            return $proceed($product, $requiredAttributeIds);
        }

        $storeId = (int)$product->getStoreId();

        try {
            $storeViewCode = (string)$this->storeManager->getStore($storeId)->getCode();
            $documents = $this->variants->forParent($storeViewCode, (int)$product->getId());
        } catch (\Throwable $e) {
            $this->logger->warning(
                'catalog-storefront variant fallback: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $proceed($product, $requiredAttributeIds);
        }

        $expected = $this->expectedChildIds($product);
        $missing = array_diff($expected, array_keys($documents));

        if ($documents === [] || $missing !== []) {
            // Say which, and how many were expected: none found points at the query or the store
            // code, a few missing points at the index being behind on those variants.
            $this->logger->info(sprintf(
                'catalog-storefront: product %d loaded its children from the database — '
                . '%d of %d variants have no document (%s)',
                (int)$product->getId(),
                count($missing) ?: count($expected),
                count($expected),
                implode(', ', array_slice($missing ?: $expected, 0, 10))
            ));

            return $proceed($product, $requiredAttributeIds);
        }

        $groupKey = $this->productPrice->groupKey((int)$this->customerSession->getCustomerGroupId());

        $children = [];
        foreach ($documents as $document) {
            $child = $this->modelBuilder->build($document, $storeId);
            if ($child === null) {
                return $proceed($product, $requiredAttributeIds);
            }

            // A loaded product returns its id as a string, because that is what the database
            // gives. ProductModelBuilder casts to int, and getJsonConfig() puts these ids straight
            // into JSON, so an int renders as 1797 where core renders "1797". Parents avoid this
            // because ListingHydration overlays the select row over them; children have no row.
            $child->setData('entity_id', (string)$child->getId());
            $this->applyPriceData($child, $document, $groupKey);

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
     *
     * @param Product $child
     * @param array $document
     * @param string $groupKey
     * @return void
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

    /**
     * The children the parent document says it has, so a partially indexed parent falls back
     * rather than rendering a short option list.
     *
     * @param Product $product
     * @return int[]
     */
    private function expectedChildIds(Product $product): array
    {
        $document = (array)$product->getData(ProductModelBuilder::DOCUMENT_KEY);
        $variantIds = (array)($document['variantIds'] ?? []);

        // Keys are prefixed ("v123") to keep the map a JSON object; a null value is a link the
        // feed reported removed.
        return array_values(array_map('intval', array_filter($variantIds, static fn($id) => $id !== null)));
    }
}
