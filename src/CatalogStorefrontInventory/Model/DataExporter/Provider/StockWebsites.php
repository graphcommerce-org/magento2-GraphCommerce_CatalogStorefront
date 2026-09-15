<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Model\DataExporter\Provider;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory;
use Magento\InventorySalesApi\Api\GetStockBySalesChannelInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adds the codes of the websites a stock sells through to a stock status row,
 * so the row says where its slice belongs. A website without a stock and a
 * stock without a website leave no code.
 */
class StockWebsites
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly GetStockBySalesChannelInterface $getStockBySalesChannel,
        private readonly SalesChannelInterfaceFactory $salesChannelFactory,
    ) {
    }

    public function get(array $values): array
    {
        $websitesByStock = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $channel = $this->salesChannelFactory->create();
            $channel->setType(SalesChannelInterface::TYPE_WEBSITE);
            $channel->setCode($website->getCode());
            try {
                $stockId = (int)$this->getStockBySalesChannel->execute($channel)->getStockId();
            } catch (NoSuchEntityException) {
                continue;
            }
            $websitesByStock[$stockId][] = $website->getCode();
        }
        $output = [];
        foreach ($values as $value) {
            foreach ($websitesByStock[(int)$value['stockId']] ?? [] as $websiteCode) {
                $output[$value['stockId'] . '_' . $value['productId'] . '_' . $websiteCode] = [
                    'stockId' => $value['stockId'],
                    'productId' => $value['productId'],
                    'websiteCodes' => $websiteCode,
                ];
            }
        }

        return $output;
    }
}
