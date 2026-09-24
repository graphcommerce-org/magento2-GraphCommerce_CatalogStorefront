<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\CollectionFlag;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\SpecialPriceMapFromDocuments;
use Magento\Catalog\Pricing\Price\SpecialPriceBulkResolverInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Eav\Model\Entity\Collection\AbstractCollection;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class SpecialPriceMapFromDocumentsTest extends TestCase
{
    private ListingDocuments $listing;

    private bool $showOutOfStock = false;

    private bool $coreAsked = false;

    private function plugin(): SpecialPriceMapFromDocuments
    {
        $this->listing = new ListingDocuments();
        $this->coreAsked = false;

        $session = $this->createMock(CustomerSession::class);
        $session->method('getCustomerGroupId')->willReturn(0);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $stock = $this->createMock(StockConfigurationInterface::class);
        $stock->method('isShowOutOfStock')->willReturnCallback(fn () => $this->showOutOfStock);

        return new SpecialPriceMapFromDocuments($this->listing, new ProductPrice(), $session, $storeManager, $stock);
    }

    private function map(array $ids, bool $flagged = true): array
    {
        $collection = $this->createMock(AbstractCollection::class);
        $collection->method('getFlag')->with(CollectionFlag::FLAG)->willReturn($flagged);
        $collection->method('getLoadedIds')->willReturn($ids);

        return $this->plugin()->aroundGenerateSpecialPriceMap(
            $this->createMock(SpecialPriceBulkResolverInterface::class),
            function () {
                $this->coreAsked = true;

                return ['core' => true];
            },
            1,
            $collection
        );
    }

    private function documents(): void
    {
        $this->listing->add('default', [
            1 => ['type' => 'simple', 'priceIndex' => [['group' => '0', 'regular' => 10.0, 'final' => 8.0]]],
            2 => ['type' => 'simple', 'priceIndex' => [['group' => '0', 'regular' => 10.0, 'final' => 10.0]]],
            3 => ['type' => 'configurable'],
        ], ['configurable' => [3 => [
            'salable' => [10.0, 10.0, 20.0, 20.0, [], false],
            'all' => [10.0, 8.0, 20.0, 20.0, [], true],
        ]]]);
    }

    public function testASimpleIsDiscountedWhenItsFinalPriceIsUnderItsRegularPrice(): void
    {
        $plugin = $this->plugin();
        $this->documents();

        $this->assertSame([1 => true, 2 => false, 3 => false], $this->mapWith($plugin, [1, 2, 3]));
    }

    public function testAConfigurableCountsEveryChildWhenOutOfStockIsShown(): void
    {
        $plugin = $this->plugin();
        $this->documents();
        $this->showOutOfStock = true;

        $this->assertSame([3 => true], $this->mapWith($plugin, [3]));
    }

    public function testAProductWithoutADocumentSendsTheListingToCore(): void
    {
        $plugin = $this->plugin();
        $this->documents();

        $this->assertSame(['core' => true], $this->mapWith($plugin, [1, 9]));
        $this->assertTrue($this->coreAsked);
    }

    public function testACoreListingIsUntouched(): void
    {
        $this->assertSame(['core' => true], $this->map([1], false));
    }

    private function mapWith(SpecialPriceMapFromDocuments $plugin, array $ids): array
    {
        $collection = $this->createMock(AbstractCollection::class);
        $collection->method('getFlag')->with(CollectionFlag::FLAG)->willReturn(true);
        $collection->method('getLoadedIds')->willReturn($ids);

        return $plugin->aroundGenerateSpecialPriceMap(
            $this->createMock(SpecialPriceBulkResolverInterface::class),
            function () {
                $this->coreAsked = true;

                return ['core' => true];
            },
            1,
            $collection
        );
    }
}
