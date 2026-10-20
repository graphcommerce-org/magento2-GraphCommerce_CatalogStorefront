# GraphCommerce_CatalogStorefrontBundleProductFrontend

1. Enable bundle detail pages and export their option flags and stock limits.

   ```sh
   bin/magento module:enable GraphCommerce_CatalogStorefrontBundleProductFrontend
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento indexer:reindex catalog_data_exporter_products inventory_data_exporter_stock_status
   bin/magento config:set catalog/storefront_documents/serve_pdp 1
   ```

2. Compare rendered pages with core.

   ```sh
   bin/magento catalog-storefront:parity:detail https://shop.example
   ```

[`OptionsFromDocument`](Plugin/OptionsFromDocument.php) builds native bundle options and selection models from documents. A selection whose child is not salable is left out unless the store shows out of stock products, as core's stock filter leaves it out.
[`SelectionPricesFromDocument`](Plugin/SelectionPricesFromDocument.php) selects the fixed or dynamic price range with document prices and stock limits.
The readers throw [`DocumentReadException`](../CatalogStorefront/Model/DocumentReadException.php) when a required child document or stock field is missing.
