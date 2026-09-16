<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Model\DataExporter\Provider;

use Magento\ConfigurableProductDataExporter\Model\Query\VariantsQuery;
use Magento\Framework\App\ResourceConnection;

/**
 * Lists a configurable's children by sku on the parent's own products feed
 * row, so both sides of the relation travel on the products feed: the child
 * names its parents in `parents`, the parent names its children here. The
 * exporter's own provider of this field answers an empty list. A disabled or
 * unsalable child stays in the list; the read side decides what it shows.
 */
class Variants
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly VariantsQuery $variantsQuery,
    ) {
    }

    public function get(array $values): array
    {
        $productIds = array_values(array_unique(array_column($values, 'productId')));
        if (!$productIds) {
            return [];
        }
        $wanted = [];
        foreach ($values as $value) {
            $wanted[$value['storeViewCode'] . '/' . $value['productId']] = true;
        }
        $output = [];
        $rows = $this->resourceConnection->getConnection()->fetchAll(
            $this->variantsQuery->getQuery(['productId' => $productIds])
        );
        foreach ($rows as $row) {
            $key = $row['storeViewCode'] . '/' . $row['productId'];
            if (isset($wanted[$key])) {
                $output[$key . '/' . $row['sku']] = [
                    'productId' => $row['productId'],
                    'storeViewCode' => $row['storeViewCode'],
                    'variants' => ['sku' => $row['sku']],
                ];
            }
        }
        ksort($output);

        return $output;
    }
}
