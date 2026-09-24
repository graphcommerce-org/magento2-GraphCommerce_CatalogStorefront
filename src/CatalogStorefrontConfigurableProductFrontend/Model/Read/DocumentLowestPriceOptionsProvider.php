<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Pricing\Price\LowestPriceOptionsProvider;
use Magento\ConfigurableProduct\Pricing\Price\LowestPriceOptionsProviderInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class DocumentLowestPriceOptionsProvider implements LowestPriceOptionsProviderInterface
{
    private const WEEE_ENABLED = 'tax/weee/enable';

    public function __construct(
        private readonly LowestPriceOptionsProvider $subject,
        private readonly ProductPrice $productPrice,
        private readonly CustomerSession $customerSession,
        private readonly ListingDocuments $listing,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProductFactory $productFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

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
            $fromRanges = $this->fromRanges($product);
            if ($fromRanges !== null) {
                return $fromRanges;
            }
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
     * The cheapest child per tax class, built from the child price ranges the listing read
     * with its documents, so a card prices without its variant documents. A tax class gets
     * its own child because the amounts compare after tax. Null when the page holds no
     * ranges for the product, when fixed product taxes apply, or when no child is priced
     * for the group: the children themselves decide then.
     *
     * @return Product[]|null
     */
    private function fromRanges(Product $parent): ?array
    {
        $storeId = (int)$parent->getStoreId();
        $store = $this->storeManager->getStore($storeId);
        $ranges = $this->listing->priceData((string)$store->getCode())['configurable'][(int)$parent->getId()] ?? null;
        if ($ranges === null || $this->scopeConfig->isSetFlag(self::WEEE_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return null;
        }

        $document = (array)$parent->getData(ProductDocumentsInterface::DOCUMENT_KEY);
        $parentSalable = (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
        $range = $this->stockConfiguration->isShowOutOfStock($storeId)
            ? ($parentSalable ? ($ranges['salable'] ?? $ranges['all']) : $ranges['all'])
            : $ranges['salable'];
        if ($range === null) {
            return null;
        }

        $byTaxClass = $range[4] ?: [(int)$parent->getTaxClassId() => $range];
        $children = [];
        foreach ($byTaxClass as $taxClassId => [$minRegular, $minFinal]) {
            $child = $this->productFactory->create();
            $child->setData([
                'type_id' => Type::TYPE_SIMPLE,
                'store_id' => $storeId,
                'tax_class_id' => (int)$taxClassId,
                'price' => $minRegular,
                'catalog_rule_price' => $minFinal < $minRegular ? $minFinal : null,
                'tier_price' => [],
            ]);
            $children[] = $child;
        }

        return $children;
    }

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
