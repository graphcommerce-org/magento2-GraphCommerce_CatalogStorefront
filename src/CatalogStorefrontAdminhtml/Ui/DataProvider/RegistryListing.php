<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider;

use GraphCommerce\CatalogStorefront\Model\Registry\Definition;
use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface as Repository;
use GraphCommerce\CatalogStorefront\Model\Registry\NativeResources;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\SourceFeedCounts;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Api\Filter;
use Magento\Ui\DataProvider\AbstractDataProvider;

class RegistryListing extends AbstractDataProvider
{
    private string $kind;
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        private readonly Repository $repository,
        private readonly Definition $definition,
        private readonly SourceFeedCounts $counts,
        private readonly NativeResources $native,
        private readonly StoreManagerInterface $stores,
        private readonly ScopeConfigInterface $scope,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->kind = str_replace(['catalog_storefront_','_listing_data_source'], '', (string)$name);
        $this->definition->table($this->kind);
    }
    public function getData(): array
    {
        $rows = $this->repository->all($this->kind);
        $types = $this->definition->types($this->kind);
        $lookup = [];
        foreach (Definition::KINDS as $kind) {
            $lookup[$kind] = array_column($this->repository->all($kind), null, 'id');
        }
        $unknown = ['value' => '—','badges' => [],'description' => 'No imported feed counts are available.'];
        $sourceCounts = $this->kind === 'sources' ? $this->counts->get() : [];
        $stockCounts = $this->kind === 'stocks' ? $this->counts->stocks() : [];
        $stocks = $this->kind === 'stocks' ? array_column($this->native->stockSnapshot()['stocks'], null, 'id') : [];
        foreach ($rows as &$row) {
            $type = $row['type'];
            $row['type'] = $types[$type];
            $row['displayCode'] = $row['code'];
            $row += ['tagBorder' => '#C7C7C7','tagColor' => '#303030','tagBg' => '#F1F1F1','currencyNote' => '','indent' => '0px','glyph' => '','groups' => []];
            $row['status'] = $row['enabled'] ? 'ACTIVE' : 'INACTIVE';
            if ($this->kind === 'views') {
                $source = $lookup['sources'][$row['source_id']] ?? null;
                $row['source'] = $source['code'] ?? '—';
                $row['stock'] = $lookup['stocks'][$row['stock_id']]['name'] ?? '—';
                $row['protection'] = strtoupper($row['protection']);
                $row['bookMode'] = match ($row['book_mode']) {
                    'all'=>'Use all available price books','single'=>'Single price book only',default=>'Allow selected price books only'
                };
                $row['bookList'] = implode(', ', array_map(static fn(int $id): string=>$lookup['books'][$id]['code'] ?? '', $row['book_ids']));
                $global = array_filter($lookup['layers'], static function (array $layer) use ($source): bool {
                    if (!$source || empty($source['enabled']) || ($layer['scope'] ?? 'view') !== 'global' || empty($layer['enabled'])
                        || $layer['type'] !== 'generic' || (!empty($layer['locale']) && $layer['locale'] !== ($source['locale'] ?? null))) return false;
                    if (!empty($layer['source_id'])) return (int)$layer['source_id'] === (int)$source['id'];
                    // Unbound legacy feeds apply only to the native Source adapter.
                    return $source['type'] === 'platform_store_view';
                });
                uasort($global, static fn(array $a, array $b): int => [(int)$a['priority'], $a['id']] <=> [(int)$b['priority'], $b['id']]);
                $codes = array_column($global, 'code');
                foreach ($row['layer_ids'] as $link) {
                    $layer = $lookup['layers'][$link['resource_id']] ?? null;
                    if ($layer && ($layer['scope'] ?? 'view') !== 'global') $codes[] = $layer['code'];
                }
                $row['layers'] = implode(', ', array_unique(array_filter($codes))) ?: '—';
                $row['policies'] = implode(', ', array_map(static fn(int $id): string=>$lookup['policies'][$id]['code'] ?? '', $row['policy_ids'])) ?: '—';
            } elseif ($this->kind === 'sources') {
                $row['origin'] = 'External';
                $feed = [];
                if ($type === 'platform_store_view') {
                    try {
                        $store = $this->stores->getStore($row['native_store_id']);
                        $code = $store->getCode();
                        $row['origin'] = 'Store view ' . $code . ' (ID ' . $store->getId() . ')';
                        $feed = array_replace($sourceCounts['*'] ?? [], $sourceCounts[$code] ?? []);
                    } catch (\Magento\Framework\Exception\NoSuchEntityException) {
                        $row['origin'] = 'Store view no longer exists';
                    }
                }
                foreach (['feedProducts','feedCategories','feedAttributes'] as $field) {
                    $row[$field] = $feed[$field] ?? $unknown;
                }
            } elseif ($this->kind === 'books') {
                $row['origin'] = $row['name'];
                $row['feedPrices'] = $unknown;
                $row['currency'] = $this->repository->bookCurrency((int)$row['id']);
                $parent = $row['parent_id'];
                $depth = 0;
                $seen = [];
                while ($parent && !isset($seen[$parent])) {
                    $seen[$parent] = true;
                    $depth++;
                    $parent = $lookup['books'][$parent]['parent_id'] ?? null;
                }
                $row['depth'] = $depth;
                $row['indent'] = ($depth * 18) . 'px';
                $row['glyph'] = $depth ? '└' : '■';
                $row['role'] = $depth > 1 ? 'Grandchild' : ($depth ? 'Child' : 'Root');
                $row['currencyNote'] = empty($lookup['books'][$row['id']]['currency']) ? 'inherited' : 'defined here';
            } elseif ($this->kind === 'stocks') {
                $stock = $stocks[$row['native_stock_id'] ?? ''] ?? [];
                $row['inventorySources'] = $type === 'generic' ? ($row['locations'] ?? []) : ($stock['inventorySources'] ?? []);
                $row['sourcesAvailable'] = $type === 'generic' || ($stock['sourcesAvailable'] ?? false);
                $row['linkedViews'] = implode(', ', array_column(array_filter($lookup['views'], static fn(array $v): bool=>(int)$v['stock_id'] === $row['id']), 'code')) ?: '—';
                $row['feedStock'] = $type === 'platform_msi' ? ($stockCounts[$row['native_stock_id']] ?? $stockCounts['*'] ?? $unknown) : $unknown;
            } elseif ($this->kind === 'layers') {
                $row['groups'] = array_map(static fn(array $f): array=>['label' => strtoupper($f['operation']) . ' ' . $f['field']], $row['fields'] ?? []);
                $row['managedBy'] = $type === 'generic' ? 'External' : $types[$type];
                $row['records'] = '—';
                $row['feedRecords'] = $unknown;
                $row['locale'] = $row['locale'] ?: 'All locales';
            } elseif ($this->kind === 'policies') {
                if ($type === 'platform_stock_visibility') {
                    $show = $this->scope->isSetFlag('cataloginventory/options/show_out_of_stock', 'store', $row['native_store_id']);
                    $row['filter'] = $show ? 'No stock restriction' : 'is_in_stock EQUALS 1';
                    $row['valueSource'] = 'PLATFORM';
                    $row['trigger'] = 'Store configuration';
                } else {
                    $row['filter'] = $row['attribute'] . ' ' . strtoupper($row['operator']) . ' ' . ($row['value_source'] === 'trigger' ? ':trigger' : str_replace("\n", ', ', $row['values']));
                    $row['valueSource'] = strtoupper($row['value_source']);
                    $row['trigger'] = $row['trigger'] ?: 'Not used';
                }
                $row['linkedViews'] = count(array_filter($lookup['views'], static fn(array $v): bool=>in_array($row['id'], $v['policy_ids'], true)));
            }
            if (($row['protection'] ?? '') === 'PRIVATE' || ($row['valueSource'] ?? '') === 'TRIGGER') {
                $row['tagBorder'] = '#1677FF'; $row['tagColor'] = '#1677FF'; $row['tagBg'] = '#F0F6FF';
            }
        }
        unset($row);
        if ($this->kind === 'books') {
            $ordered = [];
            $visit = function ($parent) use (&$visit, &$ordered, $rows): void {
                foreach ($rows as $row) {
                    if (($row['parent_id'] ?? null) == $parent) {
                                    $ordered[] = $row;
                                    $visit($row['id']);
                    }
                }
            };
            $visit(null);
            $rows = $ordered;
        }
        return ['items' => $rows,'totalRecords' => count($rows)];
    }
    public function addFilter(Filter $filter): void
    {
    }
    public function addOrder($field, $direction): void
    {
    }
    public function setLimit($offset, $size): void
    {
    }
    public function addField($field, $alias = null): void
    {
    }
    #[\ReturnTypeWillChange]
    public function count(): int
    {
        return $this->getData()['totalRecords'];
    }
}
