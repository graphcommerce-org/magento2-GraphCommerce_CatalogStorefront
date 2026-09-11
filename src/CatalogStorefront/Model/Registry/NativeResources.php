<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefront\Model\Registry;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/** Read-only presentation of native configuration. No registry writes or generic View resolution. */
class NativeResources
{
    public function __construct(
        private readonly Platform $platform,
        private readonly StoreManagerInterface $stores,
        private readonly ScopeConfigInterface $config,
        private readonly Options $options
    ) {}

    /** Inventory module supplies native Stock facts through its optional plugin. */
    public function stockSnapshot(): array { return ['stocks' => [], 'viewStocks' => []]; }

    /** Native-only display IDs are scoped to this snapshot, never persistent generic resource IDs. */
    public function snapshot(): array
    {
        $records = array_fill_keys(Definition::KINDS, []);
        $row = static fn(int $id, string $code, string $name, string $type): array =>
            ['id' => $id, 'code' => $code, 'name' => $name, 'type' => $type, 'version' => 0, 'enabled' => 1];
        $platform = $this->platform->name();
        $stocks = $this->stockSnapshot();
        foreach ($stocks['stocks'] as $stock) {
            $records['stocks'][] = $row((int)$stock['id'], 'stock-' . $stock['id'], (string)$stock['name'], 'platform_msi')
                + ['native_stock_id' => (int)$stock['id']];
        }
        $websites = $this->stores->getWebsites();
        $currencies = [];
        foreach ($websites as $website) $currencies[(string)$website->getBaseCurrencyCode()] = true;
        $rootIds = []; $websiteBooks = []; $bookId = 0;
        foreach (array_keys($currencies) as $currency) {
            $rootIds[$currency] = ++$bookId;
            $code = strtolower($platform) . '_root' . (count($currencies) > 1 ? '_' . strtolower($currency) : '');
            $records['books'][] = $row($bookId, $code, "$platform Root $currency", 'platform_root')
                + ['parent_id' => null, 'currency' => $currency, 'effective_currency' => $currency];
        }
        foreach ($websites as $website) {
            $wid = (int)$website->getId(); $currency = (string)$website->getBaseCurrencyCode();
            $id = ++$bookId; $code = (string)$website->getCode();
            $websiteBooks[$wid] = [$id];
            $records['books'][] = $row($id, $code, (string)$website->getName(), 'platform_website')
                + ['parent_id' => $rootIds[$currency], 'currency' => '', 'effective_currency' => $currency, 'native_website_id' => $wid];
            foreach ($this->options->get('native_groups') as $gid => $name) {
                $websiteBooks[$wid][] = ++$bookId;
                $records['books'][] = $row($bookId, $code . '-group-' . $gid, $website->getName() . ' / ' . $name, 'platform_customer_group')
                    + ['parent_id' => $id, 'currency' => '', 'effective_currency' => $currency, 'native_website_id' => $wid, 'native_group_id' => (int)$gid];
            }
        }
        foreach ($this->stores->getStores() as $store) {
            if (!$store->getIsActive()) continue;
            $id = (int)$store->getId(); $code = (string)$store->getCode(); $name = (string)$store->getName();
            $records['sources'][] = $row($id, $code, $name, 'platform_store_view')
                + ['native_store_id' => $id, 'document_scope' => $code, 'locale' => (string)$this->config->getValue('general/locale/code', 'store', $id)];
            $records['policies'][] = $row($id, 'stock-visibility-' . $code, 'Stock visibility / ' . $name, 'platform_stock_visibility')
                + ['native_store_id' => $id, 'restrict_stock' => !$this->config->isSetFlag('cataloginventory/options/show_out_of_stock', 'store', $id)];
            $records['views'][] = $row($id, $code, $name, 'platform_store_view')
                + ['native_store_id' => $id, 'source_id' => $id, 'stock_id' => $stocks['viewStocks'][$code]['id'] ?? null,
                    'protection' => 'public', 'book_mode' => 'selected', 'book_ids' => $websiteBooks[(int)$store->getWebsiteId()] ?? [],
                    'policy_ids' => [$id], 'layer_ids' => []];
        }
        return $records;
    }
}
