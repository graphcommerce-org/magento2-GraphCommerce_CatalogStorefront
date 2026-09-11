<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Test\Unit\Model\Adminhtml;

use GraphCommerce\CatalogStorefrontInventory\Model\Adminhtml\StockOverview;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryApi\Api\Data\StockSearchResultsInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\GetStockBySalesChannelInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

class StockOverviewTest extends TestCase
{
    public function testSharedStockKeepsOneRowWithIndependentViewBindingsAndOrderedSources(): void
    {
        $stocks = [];
        foreach ([1 => 'EU', 2 => 'Unassigned', 3 => 'Unavailable sources'] as $id => $name) {
            $stock = $this->createStub(StockInterface::class);
            $stock->method('getStockId')->willReturn($id);
            $stock->method('getName')->willReturn($name);
            $stocks[$id] = $stock;
        }
        $results = $this->createStub(StockSearchResultsInterface::class);
        $results->method('getItems')->willReturn(array_values($stocks));
        $repository = $this->createMock(StockRepositoryInterface::class);
        $repository->expects(self::once())->method('getList')->willReturn($results);
        $sourceRows = [];
        foreach ([['warehouse-de', true], ['supplier-x', false]] as [$code, $enabled]) {
            $source = $this->createStub(SourceInterface::class);
            $source->method('getSourceCode')->willReturn($code);
            $source->method('getName')->willReturn($code);
            $source->method('isEnabled')->willReturn($enabled);
            $sourceRows[] = $source;
        }
        $sources = $this->createMock(GetSourcesAssignedToStockOrderedByPriorityInterface::class);
        $sources->expects(self::exactly(3))->method('execute')->willReturnCallback(
            static fn(int $id): array => match ($id) {
                1 => $sourceRows,
                2 => [],
                3 => throw new LocalizedException(new Phrase('Source lookup failed')),
            },
        );
        $stores = [];
        foreach ([['nl', 1, true], ['de', 1, true], ['missing', 2, true], ['inactive', 1, false]] as [$code, $websiteId, $active]) {
            $store = $this->createStub(Store::class);
            $store->method('getIsActive')->willReturn($active);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $store->method('getCode')->willReturn($code);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $storeManager->expects(self::exactly(2))->method('getWebsite')->willReturnCallback(function ($id) {
            $website = $this->createStub(Website::class);
            $website->method('getCode')->willReturn('website-' . $id);
            return $website;
        });
        $factory = $this->createStub(SalesChannelInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(fn() => $this->createStub(SalesChannelInterface::class));
        $resolver = $this->createMock(GetStockBySalesChannelInterface::class);
        $calls = 0;
        $resolver->expects(self::exactly(2))->method('execute')->willReturnCallback(static function () use (&$calls, $stocks) {
            if (++$calls === 1) {
                return $stocks[1];
            }
            throw new NoSuchEntityException(new Phrase('No stock'));
        });
        $overview = new StockOverview($repository, $sources, $resolver, $factory, $storeManager);
        $data = $overview->get();
        self::assertCount(3, $data['stocks']);
        self::assertSame('de, nl', $data['stocks'][0]['linkedViews']);
        self::assertSame(['warehouse-de', 'supplier-x'], array_column($data['stocks'][0]['inventorySources'], 'code'));
        self::assertFalse($data['stocks'][0]['inventorySources'][1]['enabled']);
        self::assertSame([], $data['stocks'][1]['inventorySources']);
        self::assertTrue($data['stocks'][1]['sourcesAvailable']);
        self::assertFalse($data['stocks'][2]['sourcesAvailable']);
        self::assertSame(['nl' => ['id' => '1', 'name' => 'EU'], 'de' => ['id' => '1', 'name' => 'EU']], $data['viewStocks']);
        self::assertSame($data, $overview->get(), 'Reuse one configuration snapshot for all Admin listings.');
    }
}
