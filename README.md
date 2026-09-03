# GraphCommerce_CatalogStorefront

A catalog read model for Magento 2 / Mage-OS, built on `magento/commerce-data-export`.

The feeds (products, prices, inventory, and more) are computed by Adobe's maintained
exporter modules as ordinary Magento indexers. This module implements the delivery
seam (`ExportFeedInterface`) to assemble the feed slices into one product document
per store view, and serves catalog GraphQL reads from those documents.

## Full circle

1. **Feeds** run as indexers, writing rows to the `cde_*` feed tables.
2. **`LocalExportFeed`** routes each feed batch into the document store: the products
   feed writes the base document, the prices and inventory feeds patch their slice
   into the same document (`bulkUpdate` with `doc_as_upsert`).
3. **Storage** is the 2021 Storefront Application layer (blue/green alias, partial
   updates), ported and adapted for OpenSearch 3.
4. **Read** rebuilds a product model from the merged document
   (`ProductModelBuilder`) and hands it to the stock resolvers, on both the search
   and filter GraphQL paths. Any miss falls back to the database.

Toggle with `graphcommerce/catalog_storefront/serve_reads`.

## Parity

`dev/parity/run.php <endpoint>` runs every query in `dev/parity/queries/` against the
database path and the document path and diffs them field by field. Current: 7 of 8
identical.

## Known gaps

- **Downloadable products with links purchased separately.** The feed carries link
  prices in `optionsV2`, but `ProductModelBuilder` does not yet rebuild
  `downloadable_product_links` on the model, so `links_purchased_separately` and the
  price-range maximum differ from stock. Next iteration: reconstruct downloadable
  links from `optionsV2`.
