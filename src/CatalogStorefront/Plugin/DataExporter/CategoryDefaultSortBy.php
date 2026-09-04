<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\DataExporter;

use Magento\CatalogDataExporter\Model\Provider\Category\DefaultSortBy;

/**
 * Hands the category's own default sort attribute over, null where it is
 * unset, as core resolves the field; the exporter resolves it to the
 * configured default instead.
 */
class CategoryDefaultSortBy
{
    public function aroundGet(DefaultSortBy $subject, \Closure $proceed, array $values): array
    {
        $own = [];
        foreach ($values as $value) {
            $own[$value['categoryId'] . '/' . $value['storeViewCode']] = $value['defaultSortBy'] ?? null;
        }
        $output = $proceed($values);
        foreach ($output as $index => $row) {
            $output[$index]['defaultSortBy'] = $own[$row['categoryId'] . '/' . $row['storeViewCode']] ?? null;
        }

        return $output;
    }
}
