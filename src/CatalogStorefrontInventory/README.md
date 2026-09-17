# GraphCommerce_CatalogStorefrontInventory

The stock slice of the product documents. It depends on Magento_InventoryDataExporter and
Magento_InventorySales.

- [`Model/Document/Writer/Stock`](Model/Document/Writer/Stock.php) writes the stock status
  feed onto the documents of the store views of the websites its stock sells through, and
  only where the products feed already wrote a document. A deleted row empties the slice,
  so the products feed's `inStock` answers again.
- [`Model/DataExporter/Provider/StockItem`](Model/DataExporter/Provider/StockItem.php) adds
  the stock item's own minimum quantity and minimum and maximum sale quantities, null where
  the item takes the configured value, so a changed setting needs no export.
  `StockWebsites` adds the website codes that route the row.
