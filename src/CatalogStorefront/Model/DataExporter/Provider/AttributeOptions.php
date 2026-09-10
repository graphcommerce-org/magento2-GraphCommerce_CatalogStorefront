<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefront\Model\Read\ProductAttributeOptions;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adds to an attribute metadata row the options of its source in the store
 * view's labels and order, as the product indexer reads them. Table options
 * also carry their merchant sort order so facet hydration can resolve ties
 * without changing the source order used for indexed text. Custom sources
 * such as status and tax class remain authoritative. The empty entry a source
 * puts first is left out. The store
 * emulation runs inside an area emulation, so the feed also syncs from a
 * process without an area code, such as setup:upgrade.
 */
class AttributeOptions
{
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly AppState $appState,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly ProductAttributeOptions $productAttributeOptions,
    ) {
    }

    public function get(array $values): array
    {
        $idsByStore = [];
        foreach ($values as $value) {
            $idsByStore[$value['storeViewCode']][(int)$value['id']] = true;
        }

        return $this->appState->emulateAreaCode(Area::AREA_FRONTEND, function () use ($idsByStore): array {
            $output = [];
            foreach ($idsByStore as $storeViewCode => $ids) {
                $storeId = (int)$this->storeManager->getStore($storeViewCode)->getId();
                $tableOptions = $this->productAttributeOptions->all(array_keys($ids), $storeId);
                $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
                try {
                    foreach (array_keys($ids) as $id) {
                        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $id);
                        if (!$attribute->getId() || !$attribute->usesSource()) {
                            continue;
                        }
                        $attribute->setStoreId($storeId);
                        $source = $attribute->getSource();
                        $sortOrders = [];
                        $sourceModel = $attribute->getSourceModel();
                        $canonicalTable = $source instanceof Table
                            && ($sourceModel === null || $sourceModel === '' || $sourceModel === Table::class);
                        if ($canonicalTable) {
                            foreach ($tableOptions[$id] ?? [] as $tableOption) {
                                $sortOrders[(string)$tableOption['id']] = (int)$tableOption['sortOrder'];
                            }
                        }
                        // Preserve source order: the product indexer uses it for text attribute labels.
                        $options = $source->getAllOptions();
                        foreach ($options as $option) {
                            // Option groups carry no value; an empty option with a label stays, as in the attributes list of core
                            $value = $option['id'] ?? $option['value'] ?? null;
                            if ($value === null || is_array($value) || (trim((string)$value) === '' && trim((string)($option['label'] ?? '')) === '')) {
                                continue;
                            }
                            $documentOption = ['id' => (string)$value, 'label' => (string)($option['label'] ?? '')];
                            $documentOption['facetSortOrder'] = $sortOrders[(string)$value] ?? null;
                            $output[$storeViewCode . '_' . $id . '_' . $value] = [
                                'id' => (string)$id,
                                'storeViewCode' => $storeViewCode,
                                'options' => $documentOption,
                            ];
                        }
                    }
                } finally {
                    $this->emulation->stopEnvironmentEmulation();
                }
            }

            return $output;
        });
    }
}
