<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontInventory\Plugin\Registry;

use GraphCommerce\CatalogStorefront\Model\Registry\NativeResources;
use GraphCommerce\CatalogStorefront\Model\Registry\Options;
use GraphCommerce\CatalogStorefrontInventory\Model\Adminhtml\StockOverview;

class NativeStocks
{
    public function __construct(private readonly StockOverview $stocks)
    {
    }
    public function afterStockSnapshot(NativeResources $subject, array $result): array
    {
        return $this->stocks->get();
    }
    public function afterGet(Options $subject, array $result, string $name): array
    {
        return $name === 'native_stocks' ? array_column($this->stocks->get()['stocks'], 'name', 'id') : $result;
    }
}
