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
  field is live; the harness alone cannot. Product `sku` filters accept only
  `eq` and `in`; the harness fails a query that errors on either path.

## Boundary note

`ProductModelBuilder` decodes feed labels back to ids for status, visibility, tax
class and select-attribute options using process-cached metadata maps (EAV and
tax config, loaded once per process, not per product or per request). These are
metadata, not catalog data. If this must also leave the request path, carry the
ids in the feed instead of the labels.

## Shape

- Write: `LocalExportFeed` routes feed slices into one document per store view
  (products = base, prices = `prices.g<group code>`, inventory = `stock`,
  variants = `variantIds` on the configurable parent, reviews =
  `reviews.r<review id>` with the vote percents where the review is visible).
  `etc/et_schema.xml` extends the feed with what the read side needs and the
  exporter lacks: link position, option admin label and textual swatch value
  (`Plugin/Feed/*`), and a reviews provider that exports during indexing
  (`Model/Feed/ReviewsDataProcessor`, feed table `gc_product_reviews_feed`).
- Read: `ServeSearchFromDocuments` / `ServeFilterFromDocuments` rebuild product
  models from documents through `DocumentHydration`, which also attaches the
  variant documents when `price_range` is requested. Per-field plugins in
  `Plugin/Resolver/*` serve price_range (simple, virtual, downloadable,
  configurable), media_gallery, url_rewrites, max_sale_qty,
  configurable_options with swatch_data, downloadable links and samples,
  rating_summary, review_count, and related, upsell and crosssell products
  (fetched by sku). `Plugin/SalableFromDocument` answers the configurable and
  bundle salability check from the inventory slice.
- The product index mapping is `dynamic: false` with only `sku` mapped as a
  keyword. Every field is stored in `_source`; rich configurable documents
  otherwise exceed the 1000-field mapping limit and their writes fail silently.
- The GraphCommerce ProductList query (`dev/parity/queries/13-*.graphql`, all
  fragments and injections resolved) runs on the document path with no catalog
  SQL: only per-process metadata and bootstrap queries remain.

## Operations

- The FrankenPHP worker container has its own env file and its own Redis cache
  database. A host-side `cache:flush` or `config:set` never reaches it: flush
  inside the container (`docker exec project-backend-frankenphp-1 php
  bin/magento cache:flush`) and restart the worker after a `serve_reads` flip.
  A benchmark that flips the flag must do this, or both runs measure one path.
- With immediate export, a full `indexer:reindex` of a feed skips rows whose
  feed hash is unchanged, so it does not repair a document store. To rebuild
  the documents: drop the index, truncate the `cde_*` and `gc_*` feed tables,
  then reindex the products, prices, stock, variants and reviews feeds.
- After di.xml changes: `setup:di:compile`, `cache:flush` on the host and in
  the worker container, then restart the worker. After `et_schema.xml` changes
  every feed row changes hash, so the next reindex re-exports everything.

## Known gaps and deviations

- Price rows are served only when the display currency is the base currency,
  catalog prices exclude tax and are displayed excluding tax, and fixed product
  taxes are off. Other setups fall back to core.
- Bundle and grouped price_range fall back to core (child loads).
- The feed exports the special price attribute without its from and to dates.
- `configurable_options` falls back to core when `id` or `use_default` is
  selected: the feed carries no super attribute id.
- The inventory feed is written to every store view regardless of stock id.
  Multi-source setups with a stock per website need the stock id mapped to its
  website's store views.
- A product with many reviews carries one small entry per review in its
  document; the aggregate is computed on read.
- Intentional deviations. Core includes separately purchased downloadable link
  prices in the maximum price only when `links_purchased_separately` is loaded
  on the model, so its answer depends on the query; the document path always
  includes them. Core lists configurable options in an undefined order (no
  ORDER BY, it changes with the query plan); the document path lists them by
  position. Core cannot resolve an inline fragment inside a linked products
  selection; the document path can.
