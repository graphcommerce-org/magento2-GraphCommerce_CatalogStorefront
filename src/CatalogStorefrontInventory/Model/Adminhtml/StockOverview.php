<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model\Adminhtml;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryApi\Api\StockRepositoryInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\GetStockBySalesChannelInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Read-only MSI adapter. Websites are adapter bindings, not catalog-domain resources. */
class StockOverview
{
    private ?array $snapshot = null;

    public function __construct(
        private readonly StockRepositoryInterface $stocks,
        private readonly GetSourcesAssignedToStockOrderedByPriorityInterface $sources,
        private readonly GetStockBySalesChannelInterface $stockByChannel,
        private readonly SalesChannelInterfaceFactory $channelFactory,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /** @return array{stocks: array, viewStocks: array} */
    public function get(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }
        $items = [];
        foreach ($this->stocks->getList()->getItems() as $stock) {
            $id = (int)$stock->getStockId();
            $inventorySources = [];
            $available = true;
            try {
                // Preserve native assignment order. Disabled sources remain visible as configuration.
                foreach ($this->sources->execute($id) as $source) {
                    $inventorySources[] = [
                        'code' => (string)$source->getSourceCode(),
                        'name' => (string)$source->getName(),
                        'enabled' => (bool)$source->isEnabled(),
                    ];
                }
            } catch (\Magento\Framework\Exception\LocalizedException) {
                $available = false;
            }
            $items[$id] = [
                'id' => (string)$id,
                'name' => (string)$stock->getName(),
                'type' => 'Magento MSI Stock',
                'inventorySources' => $inventorySources,
                'sourcesAvailable' => $available,
                'linkedViews' => [],
            ];
        }
        $viewStocks = [];
        $websiteStocks = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!(bool)$store->getIsActive()) {
                continue;
            }
            $websiteId = (int)$store->getWebsiteId();
            if (!array_key_exists($websiteId, $websiteStocks)) {
                $website = $this->storeManager->getWebsite($websiteId);
                $channel = $this->channelFactory->create();
                $channel->setType(SalesChannelInterface::TYPE_WEBSITE);
                $channel->setCode((string)$website->getCode());
                try {
                    $websiteStocks[$websiteId] = (int)$this->stockByChannel->execute($channel)->getStockId();
                } catch (NoSuchEntityException) {
                    $websiteStocks[$websiteId] = null;
                }
            }
            $id = $websiteStocks[$websiteId];
            if ($id !== null && isset($items[$id])) {
                $code = (string)$store->getCode();
                $viewStocks[$code] = ['id' => (string)$id, 'name' => $items[$id]['name']];
                $items[$id]['linkedViews'][] = $code;
            }
        }
        ksort($items, SORT_NUMERIC);
        foreach ($items as &$item) {
            sort($item['linkedViews'], SORT_STRING);
            $item['linkedViews'] = implode(', ', $item['linkedViews']) ?: '';
        }
        unset($item);
        return $this->snapshot = ['stocks' => array_values($items), 'viewStocks' => $viewStocks];
    }
}
