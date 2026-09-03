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
  (products = base, prices = `prices.g<group code>` plus `priceIndex.<group
  key>` with regular and final price per customer group and the fallback row
  resolved, inventory = `stock`, variants = `variantIds` on the configurable
  parent, reviews = `reviews.r<review id>` with the vote percents where the
  review is visible). `Model/ProductPrice` holds the price semantics both sides
  share.
  `etc/et_schema.xml` extends the feed with what the read side needs and the
  exporter lacks: link position, option admin label and textual swatch value
  (`Plugin/Feed/*`), and a reviews provider that exports during indexing
  (`Model/Feed/ReviewsDataProcessor`; `etc/db_schema.xml` adds the modern feed
  columns to the exporter's own `catalog_data_exporter_product_reviews`).
- Read: `ServeSearchFromDocuments` / `ServeFilterFromDocuments` rebuild product
  models from documents through `DocumentHydration`. A listing page is one
  multi-search request: the documents by id with the heavy keys the query does
  not select left out (`HEAVY_KEYS`, `attributes` only when a non-base field is
  selected), and, when `price_range` is selected, the configurable price
  aggregation: terms on `parentIds`, min and max of `priceIndex.<group key>`
  over salable and over all enabled variants. Per-field plugins in
  `Plugin/Resolver/*` serve price_range (simple, virtual, downloadable,
  configurable), media_gallery, url_rewrites, max_sale_qty,
  configurable_options with swatch_data, downloadable links and samples,
  rating_summary, review_count, and related, upsell and crosssell products
  (fetched by sku). `Plugin/SalableFromDocument` answers the configurable and
  bundle salability check from the inventory slice.
- The product index mapping is `dynamic: false`; only `sku`, `parentIds`,
  `status`, `stock.isSalable` and the `priceIndex` floats are mapped, because
  requests filter or aggregate on them. Every field is stored in `_source`; rich
  configurable documents otherwise exceed the 1000-field mapping limit and
  their writes fail silently. Wildcard `_source` filters are very slow: never.
- A listing page costs two OpenSearch round trips on the document path: the
  core product search, then the multi-search above. Writes are visible to the
  aggregation after the index refresh (one second by default); the fetch by id
  is immediate.
- Per-process memos, allowed because they hold metadata or pure derivations:
  `ImageUrlMemo` (image URL per type, file and store), `FacetLabelsMemo`
  (facet attribute and option labels), `Plugin/Search/FieldNameMemo` (search
  index field name per attribute code and context; the core mapper otherwise
  loads the attribute for each of the 24 facet buckets on every request). A
  changed label, attribute or media configuration reaches a worker at its next
  restart. `etc/config.xml` turns on `dev/caching/cache_user_defined_attributes`
  so the EAV config serves user-defined attributes from cache instead of SQL.
- `Plugin/GraphQl/ValidateOncePerProcess` validates a query document once per
  process and executes repeats with an empty rule set. `Plugin/GraphQl/ReuseSchema`
  keeps one built schema per query shape: Magento prunes every type to the
  names the query uses, so a schema belongs to that name set, and its type map
  is materialized at build time because the type registry resets between
  requests. Both hold state for the worker's lifetime; a schema or config
  change reaches a worker at its next restart.
- `configurable_options` is built at index time (`Model/Feed/ConfigurableOptionsBuilder`,
  document key `configurableOptions`) and returned as is; the per-value `uid`
  and `swatch_data` fields still run through Magento's resolver wrapper, about
  3µs per call, which a schema redeclaration would remove for document-served
  products only at the cost of core-served ones.
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
  the documents: drop the index, truncate the `cde_*` feed tables and
  `catalog_data_exporter_product_reviews`, then reindex the products, prices,
  stock, variants and reviews feeds.
- After di.xml changes: `setup:di:compile`, `cache:flush` on the host and in
  the worker container, then restart the worker. After `et_schema.xml` changes
  every feed row changes hash, so the next reindex re-exports everything.
- The worker runs 25 PHP threads, each with its own process state (memos,
  kept schemas, validated documents). Sequential requests alternate between
  two threads, so the first two hits of a query shape after a restart are cold
  (about 250ms and 170ms for the 200-item listing); a variable change costs
  nothing. Under concurrent load every thread pays that once per shape: warm
  the worker after a deploy, or set `num` on the web-worker in the Caddyfile.
- Measure server side, not only over the wire: the proxy chain and a TLS
  handshake add 10 to 20ms, and the first request after a few idle seconds
  pays 40 to 60ms of wake-up over the wire (15 to 25ms inside PHP, spread
  evenly over OpenSearch, MySQL and Redis). Steady state for the 200-item
  GraphCommerce listing is 70 to 80ms inside PHP: core search build 7 to 10ms
  (OpenSearch itself 1ms plus three price-bucket queries), document multi-search
  14 to 16ms (OpenSearch 11ms, of which about 6ms is `_source` filtering), 25
  SQL queries in 3 to 5ms (grouped and bundle price fallback, layer category),
  the rest is the GraphQL executor and resolvers.

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
