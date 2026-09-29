# GraphCommerce_CatalogStorefrontGroupedProductFrontend

1. Enable grouped detail pages and export their option flags and stock limits.

   ```sh
   bin/magento module:enable GraphCommerce_CatalogStorefrontGroupedProductFrontend
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento indexer:reindex catalog_data_exporter_products inventory_data_exporter_stock_status
   bin/magento config:set catalog/storefront_documents/serve_pdp 1
   ```

2. Compare rendered pages with core.

   ```sh
   bin/magento catalog-storefront:parity:detail https://shop.example
   ```

[`AssociatedProductsFromDocument`](Plugin/AssociatedProductsFromDocument.php) selects and orders grouped children from documents.
[`QuantityFromDocument`](Plugin/QuantityFromDocument.php) supplies quantity limits and increments from stock documents and store configuration.
The readers throw [`DocumentReadException`](../CatalogStorefront/Model/DocumentReadException.php) when a required child document or stock field is missing.
