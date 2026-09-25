<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use Magento\Catalog\Pricing\Price\SpecialPriceBulkResolverInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The special price map of a document listing, from the documents and the child price ranges
 * the listing read: a product with a final price under its regular price for the customer
 * group, and a composite with such a child. A listing with a product the page holds no
 * document for takes core's query.
 */
class SpecialPriceMapFromDocuments
{
    public function __construct(
        private readonly ListingDocuments $listing,
        private readonly ProductPrice $productPrice,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockConfigurationInterface $stockConfiguration,
    ) {
    }

    /**
     * @return array<int, bool>
     */
    public function aroundGenerateSpecialPriceMap(
        SpecialPriceBulkResolverInterface $subject,
        \Closure $proceed,
        int $storeId,
        ?AbstractCollection $productCollection
    ): array {
        if (!$productCollection || !$productCollection->getFlag(CollectionFlag::FLAG)) {
            return $proceed($storeId, $productCollection);
        }

        $storeViewCode = (string)$this->storeManager->getStore($storeId)->getCode();
        $documents = $this->listing->documents($storeViewCode);
        $ranges = $this->listing->priceData($storeViewCode)['configurable'] ?? [];
        $groupKey = $this->productPrice->groupKey((int)$this->customerSession->getCustomerGroupId());
        $showOutOfStock = $this->stockConfiguration->isShowOutOfStock($storeId);

        $map = [];
        foreach ($productCollection->getLoadedIds() as $id) {
            $document = $documents[(int)$id] ?? null;
            if ($document === null) {
                return $proceed($storeId, $productCollection);
            }
            if (($document['type'] ?? null) === Configurable::TYPE_CODE) {
                $range = $ranges[(int)$id] ?? null;
                $chosen = $showOutOfStock ? ($range['all'] ?? null) : ($range['salable'] ?? null);
                $map[(int)$id] = (bool)($chosen[5] ?? false);
                continue;
            }
            $entry = $this->productPrice->indexEntry((array)($document['priceIndex'] ?? []), $groupKey);
            $map[(int)$id] = $entry !== null && $entry['final'] < $entry['regular'];
        }

        return $map;
    }
}
