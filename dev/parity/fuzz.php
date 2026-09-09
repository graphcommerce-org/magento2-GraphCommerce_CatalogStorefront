<?php
/**
 * A random configuration set for the parity gate: `path=value` lines, six
 * rows of the table with a value other than the default. The seed selects
 * the set; CI seeds a push with its run id and a workflow dispatch replays
 * one. A seed that fails the gate becomes a fixed setup of the matrix.
 *
 *   php dev/parity/fuzz.php <seed> [<rows>]
 */
declare(strict_types=1);

$table = [
    'tax/display/type' => ['1', '2', '3'],
    'tax/calculation/price_includes_tax' => ['0', '1'],
    'tax/calculation/cross_border_trade_enabled' => ['0', '1'],
    'tax/calculation/based_on' => ['shipping', 'billing', 'origin'],
    'tax/defaults/region' => ['0', '33'],
    'tax/defaults/postcode' => ['*', '48201'],
    'tax/weee/enable' => ['0', '1'],
    'tax/weee/display_list' => ['0', '1', '2', '3'],
    'tax/weee/apply_vat' => ['0', '1'],
    'currency/options/default' => ['USD', 'EUR'],
    'catalog/price/scope' => ['0', '1'],
    'cataloginventory/options/show_out_of_stock' => ['0', '1'],
    'cataloginventory/options/stock_threshold_qty' => ['0', '5'],
    'cataloginventory/item_options/min_sale_qty' => ['1', '2'],
    'cataloginventory/item_options/max_sale_qty' => ['10000', '5'],
    'catalog/seo/product_url_suffix' => ['.html', ''],
    'catalog/seo/category_url_suffix' => ['.html', ''],
    'catalog/layered_navigation/price_range_calculation' => ['auto', 'manual', 'improved'],
    'catalog/layered_navigation/price_range_step' => ['100', '50'],
    'catalog/layered_navigation/price_range_max_intervals' => ['10', '3'],
    'catalog/layered_navigation/one_price_interval' => ['0', '1'],
    'catalog/layered_navigation/interval_division_limit' => ['9', '3'],
    'catalog/layered_navigation/display_category' => ['1', '0'],
    'catalog/layered_navigation/display_product_count' => ['1', '0'],
    'catalog/search/min_query_length' => ['3', '1'],
    'catalog/review/active' => ['1', '0'],
    'catalog/frontend/flat_catalog_product' => ['0', '1'],
    'catalog/frontend/flat_catalog_category' => ['0', '1'],
    'sales/msrp/enabled' => ['0', '1'],
    'customer/account_share/scope' => ['1', '0'],
    'general/locale/code' => ['en_US', 'nl_NL'],
];

$seed = (int)($argv[1] ?? time());
mt_srand($seed);
$paths = array_keys($table);
shuffle($paths);
foreach (array_slice($paths, 0, (int)($argv[2] ?? 6)) as $path) {
    $values = array_slice($table[$path], 1);
    printf("%s=%s\n", $path, $values[mt_rand(0, count($values) - 1)]);
}
fprintf(STDERR, "seed %d\n", $seed);
