<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Feed;

use Magento\ConfigurableProductDataExporter\Model\Provider\Product\Options;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Swatches\Model\Swatch;

/**
 * Adds to every configurable option value the admin label and the textual
 * swatch value, which the feed does not export and the configurable_options
 * resolver returns. Runs at index time.
 */
class OptionValueDetails
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly Uid $uidEncoder,
    ) {
    }

    public function afterGet(Options $subject, array $output): array
    {
        $optionIds = [];
        foreach ($output as $row) {
            foreach ((array)($row['optionsV2']['values'] ?? []) as $value) {
                $optionIds[] = (int)explode('/', $this->uidEncoder->decode((string)$value['id']))[2];
            }
        }
        if (!$optionIds) {
            return $output;
        }
        $connection = $this->resourceConnection->getConnection();
        $labels = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('eav_attribute_option_value'), ['option_id', 'value'])
                ->where('option_id IN (?)', array_unique($optionIds))
                ->where('store_id = 0')
        );
        $swatches = [];
        $swatchRows = $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName('eav_attribute_option_swatch'), ['option_id', 'store_id', 'value'])
                ->where('option_id IN (?)', array_unique($optionIds))
                ->where('type = ?', Swatch::SWATCH_TYPE_TEXTUAL)
        );
        foreach ($swatchRows as $swatch) {
            $swatches[(int)$swatch['option_id']][(int)$swatch['store_id']] = $swatch['value'];
        }

        foreach ($output as $key => $row) {
            $storeId = (int)$this->storeRepository->get($row['storeViewCode'])->getId();
            foreach ((array)($row['optionsV2']['values'] ?? []) as $index => $value) {
                $optionId = (int)explode('/', $this->uidEncoder->decode((string)$value['id']))[2];
                // A store-specific textual swatch wins when it is not empty, as the
                // core swatch helper resolves it.
                $storeValue = $swatches[$optionId][$storeId] ?? '';
                $output[$key]['optionsV2']['values'][$index] += [
                    'defaultLabel' => $labels[$optionId] ?? $value['label'],
                    'textSwatchValue' => $storeValue !== '' ? $storeValue : ($swatches[$optionId][0] ?? null),
                ];
            }
        }

        return $output;
    }
}
