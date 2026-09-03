# GraphCommerce_CatalogStorefront

Catalog read model: feed documents in OpenSearch serve the catalog GraphQL read path.

## Rules

- A GraphQL request MUST NOT run any custom SQL lookup. Every piece of data a
  request needs MUST come from the OpenSearch document. Index-time feed
  processing (the `ExportFeedInterface` implementation and feed plugins, run by
  `indexer:reindex`) MAY use SQL to assemble documents; the request path may not.
- The read path MUST fall back to the core resolver whenever the document lacks
  what a field needs, never to a database read of its own.
- Parity is the gate: `dev/parity/run.php <endpoint>` MUST stay green before a
  change ships. Add a query for every field a new plugin serves. A poison test
  (edit a document in OpenSearch, see the change in the response) proves a
  field is live; the harness alone cannot.

## Boundary note

`ProductModelBuilder` decodes feed labels back to ids for status, visibility, tax
class and select-attribute options using process-cached metadata maps (EAV and
tax config, loaded once per process, not per product or per request). These are
metadata, not catalog data. If this must also leave the request path, carry the
ids in the feed instead of the labels.

## Shape

- Write: `LocalExportFeed` routes feed slices into one document per store view
  (products = base, prices = `prices.g<group code>`, inventory = `stock`,
  variants = `variantIds` on the configurable parent). `Plugin/Feed/LinkPosition`
  adds the link position the feed lacks (`etc/et_schema.xml` extends `Link`).
- Read: `ServeSearchFromDocuments` / `ServeFilterFromDocuments` rebuild product
  models from documents through `DocumentHydration`, which also attaches the
  variant documents when `price_range` is requested. Per-field plugins in
  `Plugin/Resolver/*` serve price_range (simple, virtual, downloadable,
  configurable), media_gallery, url_rewrites, max_sale_qty,
  configurable_options with color and image swatches, downloadable links and
  samples, and related, upsell and crosssell products (fetched by sku).
  `Plugin/SalableFromDocument` answers the configurable and bundle salability
  check from the inventory slice.
- The product index mapping is `dynamic: false` with only `sku` mapped as a
  keyword. Every field is stored in `_source`; rich configurable documents
  otherwise exceed the 1000-field mapping limit and their writes fail silently.

## Operations

- The FrankenPHP worker keeps the scope config in memory. A `serve_reads` flip
  (or any config change) needs a worker restart; a cache clean is not enough.
- With immediate export, a full `indexer:reindex` of a feed skips rows whose
  feed hash is unchanged, so it does not repair a document store. To rebuild
  the documents, truncate the `cde_*` feed tables first, then reindex.
- After di.xml changes: `setup:di:compile`, `cache:flush` on the host and in
  the worker container, then restart the worker.

## Known gaps and deviations

- Price rows are served only when the display currency is the base currency,
  catalog prices exclude tax and are displayed excluding tax, and fixed product
  taxes are off. Other setups fall back to core.
- Bundle and grouped price_range fall back to core (child loads).
- The feed exports the special price attribute without its from and to dates,
  and textual swatch values are not exported at all, so text swatches fall back
  to core (one query per option value per process).
- `configurable_options` falls back to core when `id`, `use_default`,
  `default_label`, `store_label` or `use_default_value` are selected: the feed
  carries neither the super attribute id nor admin labels.
- The inventory feed is written to every store view regardless of stock id.
  Multi-source setups with a stock per website need the stock id mapped to its
  website's store views.
- Reviews and rating summary are not served from documents yet.
- Intentional deviation: core includes separately purchased downloadable link
  prices in the maximum price only when `links_purchased_separately` is loaded
  on the model, so its answer depends on the query. The document path always
  includes them.
