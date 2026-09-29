# GraphCommerce_CatalogStorefront

The base module: feed rows in, documents out, product models for any frontend.

- [`Model/Document/Delivery`](Model/Document/Delivery.php) implements the exporter's
  `ExportFeedInterface`. It hands every feed batch to the writers of that feed (di.xml
  `writers`) and then purges the cache tags of the products and categories the batch holds
  (di.xml `identities`).
- The writers of the products, prices, categories, attributes and the two scopes feeds.
  [`Model/Document/Scopes`](Model/Document/Scopes.php) reads the store views with their
  media URLs, the website ids and the customer groups from the two global scope documents,
  and every writer fans its rows out over them.
- `Model/DataExporter/` adds to the core feeds what the read side needs: the attribute set,
  the raw custom attributes, the website ids, the tax class, the image media paths, the
  link position, the category sort order and price range, the attribute options with their
  layer position, and the final price with the decimals its source is rounded to.
- `Model/Read/` builds product models from documents
  ([`ProductModelBuilder`](Model/Read/ProductModelBuilder.php)) and reads the attribute
  documents.
- [`Model/Mode`](Model/Mode.php) answers the path of the request, and every request-time
  plugin of the package asks it before it reads a document.
  [`Model/Strict`](Model/Strict.php) stops failed document reads and reports the reason on keyed requests.
- The commands `catalog-storefront:rebuild` and `catalog-storefront:status`, and the
  settings under Catalog > Catalog > Catalog Storefront Document Store.

Depends on Magento_Catalog, Magento_Store, Magento_Customer, and the exporter modules of
the feeds it writes: Magento_DataExporter, Magento_CatalogDataExporter,
Magento_ProductPriceDataExporter, Magento_ScopesDataExporter,
Magento_CatalogUrlRewriteDataExporter, Magento_ParentProductDataExporter and
Magento_CatalogInventoryDataExporter.

A module adds its feed indexers under `feeds` on [`Model/Feeds`](Model/Feeds.php), so the
rebuild and the status command cover them.
