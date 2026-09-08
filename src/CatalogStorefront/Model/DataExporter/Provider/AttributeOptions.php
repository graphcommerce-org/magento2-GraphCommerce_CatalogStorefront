<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adds to an attribute metadata row the options of its source in the store
 * view's labels and order, as core lists them on read: the option table for
 * a user attribute, the source model for an attribute like status or the tax
 * class. The empty entry a source puts first is left out. The store
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
                $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
                try {
                    foreach (array_keys($ids) as $id) {
                        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $id);
                        if (!$attribute->getId() || !$attribute->usesSource()) {
                            continue;
                        }
                        $attribute->setStoreId($storeId);
                        foreach ($attribute->getSource()->getAllOptions() as $option) {
                            // Option groups carry no value; an empty option with a label stays, as in the attributes list of core
                            if (!isset($option['value']) || is_array($option['value']) || (trim((string)$option['value']) === '' && trim((string)($option['label'] ?? '')) === '')) {
                                continue;
                            }
                            $output[$storeViewCode . '_' . $id . '_' . $option['value']] = [
                                'id' => (string)$id,
                                'storeViewCode' => $storeViewCode,
                                'options' => ['id' => (string)$option['value'], 'label' => (string)($option['label'] ?? '')],
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
