# GraphCommerce Catalog Storefront

A catalog read model for Magento 2 / Mage-OS, built on `magento/commerce-data-export`.

The feeds (products, prices, inventory, variants, reviews, categories, attributes)
are computed by the maintained exporter modules as ordinary Magento indexers. This
package implements the delivery seam (`ExportFeedInterface`) to assemble the feed
slices into one product document per store view in the configured search engine,
and serves catalog GraphQL reads from those documents. A request runs no SQL of its
own; any miss falls back to the core resolver.

Toggle with `graphcommerce/catalog_storefront/serve_reads`.

## Modules

One composer package registers one module per directory under `src/`:

| Directory | Module | Serves |
| --- | --- | --- |
| `Api` | `GraphCommerce_CatalogStorefrontApi` | The contracts: prefillers, price ranges, hydration, feed appliers, document enrichers |
| `CatalogStorefront` | `GraphCommerce_CatalogStorefront` | Storage, the export feed, listings and layered navigation, categories, media, URL rewrites, custom attributes, product links, prices |
| `Inventory` | `GraphCommerce_CatalogStorefrontInventory` | Stock status, only_x_left_in_stock, quantity, min and max sale qty |
| `ConfigurableProduct` | `GraphCommerce_CatalogStorefrontConfigurableProduct` | Configurable options, variants, options selection, the configurable price range |
| `BundleProduct` | `GraphCommerce_CatalogStorefrontBundleProduct` | Bundle items, price details, the bundle price range |
| `GroupedProduct` | `GraphCommerce_CatalogStorefrontGroupedProduct` | Grouped items, the grouped price range |
| `Downloadable` | `GraphCommerce_CatalogStorefrontDownloadable` | Downloadable links and samples |
| `Review` | `GraphCommerce_CatalogStorefrontReview` | Reviews, rating summary and breakdown |

Each module depends only on the core modules it plugs into. Enable the ones the
shop's product types and features need.

## Extending

A module registers its parts through di.xml:

- `appliers` on `LocalExportFeed`: a `FeedApplierInterface` per feed name, writing that
  feed's slice of the documents at index time.
- `enrichers` on the products applier: a `ProductDocumentEnricherInterface` adds to the
  product documents what the read side needs and the feed lacks.
- `prefillers` on `DocumentHydration`: a `PrefillerInterface` fills fields on the
  product value from the model and the document, so the executor returns them without
  a resolver call. List the fields under `prefilledFields` on `ReuseSchema`.
- `ranges` on the price prefiller: a `PriceRangeInterface` per product type id.
- `fieldDocumentKeys` and `baseFields` on `DocumentHydration` tell a listing fetch
  which document keys a field needs.

## Parity

`dev/parity/run.php <endpoint>` runs every query in `dev/parity/queries/` against the
database path and the document path, diffs the responses and, with the attribution
module enabled in the worker, fails a document-path query that runs a SQL lookup.
See `CLAUDE.md` for the operating notes and the known deviations.
