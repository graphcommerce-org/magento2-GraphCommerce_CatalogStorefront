<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductListing\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Pricing\Price\LowestPriceOptionsProvider;
use Magento\ConfigurableProduct\Pricing\Price\LowestPriceOptionsProviderInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

/**
 * Supplies the children a configurable's price is calculated from.
 *
 * Core runs a UNION query to find the cheapest child per price type and loads those into a product
 * collection — two queries per configurable card. The children are already on the parent by then,
 * built from documents by UsedProductsFromDocuments and memoised there, so this asks the type
 * instance for them instead.
 *
 * Anything it cannot serve goes to the core provider it wraps, never to a query of its own.
 */
class DocumentLowestPriceOptionsProvider implements LowestPriceOptionsProviderInterface
{
    public function __construct(
        private readonly LowestPriceOptionsProvider $subject,
        private readonly ProductPrice $productPrice,
        private readonly CustomerSession $customerSession,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param ProductInterface $product
     * @return ProductInterface[]
     */
    public function getProducts(ProductInterface $product)
    {
        if (!$product instanceof Product
            || $product->getTypeId() !== Configurable::TYPE_CODE
            || !is_array($product->getData(ProductDocumentsInterface::DOCUMENT_KEY))
        ) {
            return $this->subject->getProducts($product);
        }

        $type = $product->getTypeInstance();
        if (!$type instanceof Configurable) {
            return $this->subject->getProducts($product);
        }

        try {
            $children = $type->getUsedProducts($product);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'catalog-storefront lowest price fallback: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this->subject->getProducts($product);
        }

        if (!$children) {
            $this->logger->info(sprintf(
                'catalog-storefront: product %d priced from the database, it has no children',
                (int)$product->getId()
            ));

            return $this->subject->getProducts($product);
        }

        return $this->cheapest($children, $product);
    }

    /**
     * The cheapest child by final price and by regular price, chosen from the documents.
     *
     * Handing back every child gives the right answer — the callers take a minimum — but not the
     * right cost: each one's getPriceInfo() runs its own tier price and catalog rule lookup, so a
     * page of twelve configurables with fifteen variants each pays 360 queries. Core returns a
     * small set for exactly this reason.
     *
     * Both are needed: ConfigurablePriceResolver minimises the final price and
     * ConfigurableRegularPrice the regular one, and on a catalogue with per-variant discounts
     * those can be different children.
     *
     * @param ProductInterface[] $children
     * @return ProductInterface[]
     */
    private function cheapest(array $children, Product $parent): array
    {
        $groupKey = $this->productPrice->groupKey((int)$this->customerSession->getCustomerGroupId());

        $byFinal = null;
        $byRegular = null;

        foreach ($children as $child) {
            $document = $child instanceof Product
                ? $child->getData(ProductDocumentsInterface::DOCUMENT_KEY)
                : null;
            $entry = is_array($document)
                ? $this->productPrice->indexEntry((array)($document['priceIndex'] ?? []), $groupKey)
                : null;

            // A child with no indexed price for this group cannot be ranked. Rather than risk
            // picking a child that is not actually the cheapest, hand back the whole set and let
            // the callers minimise over it as before — correct, just not as cheap.
            if ($entry === null) {
                $this->logger->info(sprintf(
                    'catalog-storefront: product %d ranks its children in PHP — child %d has no '
                    . 'priceIndex entry for group %s',
                    (int)$parent->getId(),
                    (int)$child->getId(),
                    $groupKey
                ));

                return $children;
            }

            if ($byFinal === null || $entry['final'] < $byFinal[1]) {
                $byFinal = [$child, $entry['final']];
            }
            if ($byRegular === null || $entry['regular'] < $byRegular[1]) {
                $byRegular = [$child, $entry['regular']];
            }
        }

        $cheapest = [$byFinal[0]];
        if ($byRegular[0] !== $byFinal[0]) {
            $cheapest[] = $byRegular[0];
        }

        return $cheapest;
    }
}
