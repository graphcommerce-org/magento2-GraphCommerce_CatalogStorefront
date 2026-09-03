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
  share. `Model/Feed/CompositeLinks` keeps the grouped and bundle links by id
  (`groupedParentIds` and `bundleParentIds` on the children, the child id
  lists on the parent) from the products feed, which carries them by sku only,
  written from whichever side the feed delivers last. The feed folds a fixed
  bundle price type into the product type `bundle_fixed`;
  `Model/Feed/BundleAttributesProvider` adds the sku and shipment type the
  exporter lacks. The categories feed lands as one category document per
  store view (`Model/Storage/CategoryDocumentStorage`, index
  `<alias>_category_<store view>`, read by id only).
- Facet layer: `Plugin/Layer/CategoryFacetFromDocuments` builds the category
  bucket from the category documents (store tree membership by path, store
  view names, direct children and their activity for a category-filtered
  query); `RootCategoryFromStore` takes the root category id from the store
  model; `PriceRangeStepMemo` keeps the current category's price step per
  process; `Plugin/CacheId/CustomerTaxRateMemo` keeps the response cache id's
  tax factor per store, group and customer per process;
  `Plugin/Deploy/ConfigChangeMemo` answers core's deployment config hash check
  once per process (a detected change is not kept, so an import lifts it). With
  these, a listing request runs no SQL.
  `etc/et_schema.xml` extends the feed with what the read side needs and the
  exporter lacks: link position, option admin label and textual swatch value
  (`Plugin/Feed/*`), and a reviews provider that exports during indexing
  (`Model/Feed/ReviewsDataProcessor`; `etc/db_schema.xml` adds the modern feed
  columns to the exporter's own `catalog_data_exporter_product_reviews`).
- Read: `ServeSearchFromDocuments` / `ServeFilterFromDocuments` rebuild product
  models from documents through `DocumentHydration`. A listing page is one
  multi-search request: the documents by id with the heavy keys the query does
  not select left out (`HEAVY_KEYS`, `attributes` only when a non-base field is
  selected), and, when `price_range` is selected, the composite price data:
  the configurable and the grouped price aggregation (terms on `parentIds`
  and on `groupedParentIds`, min and max of `priceIndex.<group key>` over
  salable and over all enabled children), the bundle selection documents by
  `bundleParentIds`, and the bundles' option slices. A grouped range is the
  lowest regular and lowest final child price, as core takes them; a bundle
  range is `Model/Read/BundlePriceRange`, a port of core's bundle amount
  calculator over the option slice and the selection documents.
  `Model/Read/Prefill` then fills,
  on the product value the executor hands to child fields, the fields whose
  core resolvers only derive from the model and the document: uid, id,
  new_from_date, new_to_date, rating_summary, review_count, image, small_image
  and thumbnail (url through `Model/Read/ImageUrl`, label) and price_range
  (simple, virtual, downloadable, configurable). `ReuseSchema` routes those
  fields (di.xml `prefilledFields`, per type or interface name) to the
  pre-filled value and to the core resolver when the parent carries none, so
  core-served products and what a document cannot answer keep the core path.
  A pre-filled field costs the executor a plain array read instead of a
  resolver call with its ResolveInfo object, argument validation and four
  plugins (about 4µs per call). Per-field plugins in `Plugin/Resolver/*` serve
  media_gallery, url_rewrites, max_sale_qty, configurable_options, downloadable
  links and samples, and related, upsell and crosssell products (fetched by
  sku). `Plugin/SalableFromDocument` answers the configurable and bundle
  salability check from the inventory slice.
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
  `Model/Read/ImageUrl` (image URL per store, type and file), `FacetLabelsMemo`
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
  document key `configurableOptions`) and returned as is; its values carry the
  pre-filled `uid` and `swatch_data`.
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
  the documents: drop the index, truncate `cde_products_feed`,
  `cde_product_prices_feed`, `cde_product_variants_feed`,
  `inventory_data_exporter_stock_status_feed` and
  `catalog_data_exporter_product_reviews`, then reindex the products, stock,
  prices, variants and reviews feeds. A mapping change needs this too. The
  category documents rebuild the same way: drop the category index, truncate
  `cde_categories_feed`, reindex the categories feed.
- The parity set needs the fixed bundle `GC-BUNDLE-FIXED`
  (`dev/parity/fixtures/bundle-fixed.json`, POST it to `/rest/V1/products`
  with an admin token, then reindex stock, price, search and the feeds): the
  demo catalog has only a dynamic bundle with required radio options. The
  harness fails a query that returns no product on either path, because a
  hidden product passed vacuously for hours before that check existed.
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
  handshake add 2 to 7ms, and the first request after a few idle seconds
  pays 40 to 60ms of wake-up over the wire (15 to 25ms inside PHP, spread
  evenly over OpenSearch, MySQL and Redis).
- `dev/attribution` prints the full latency stack of one query: wire, proxy
  chain, FrankenPHP, PHP launch, dispatch, parse, schema, execution, every
  resolver class by self time with the plugin chain around it, the search
  adapter and the document listing down to OpenSearch's own `took`, SQL, Redis
  and ResolveInfo creation. It is a separate module so the request path stays
  free of it: link `dev/attribution/Module` to
  `app/code/GraphCommerce/CatalogStorefrontAttribution`, `module:enable` it,
  compile, flush, restart the worker, then
  `dev/attribution/run.sh <container> <proxy graphql url> <query file>`; disable
  and recompile afterwards. The instrumentation itself costs about 5ms on the
  200-item listing. Steady state of that listing, measured this way: 71ms over
  the wire, 62ms inside PHP: execute 56ms of which the products resolver is
  18ms (core search adapter 7ms with OpenSearch at 1ms, document multi-search
  16ms with OpenSearch at 12 to 17ms for the five searches, of which about 6ms
  is `_source` filtering, model build 2ms), the other resolvers 2ms (150
  resolver calls), and the webonyx walk over about 22000 fields 25ms; the
  plugins around the controller 1ms, response build 3ms (JSON render 0.7ms);
  no SQL. The state reset after the
  response takes 22ms per request on the thread, which is throughput, not
  latency. PHP JIT (tracing and function mode) makes this workload 10 to 20%
  slower in the worker and, combined with the kept schema, produced erratic
  "Unknown type Query" errors: keep it off.
- If every `bin/magento` command fails with a missing `Interceptor` class, the
  interception cache is poisoned and `cache:flush` cannot run either: flush
  Redis directly (`docker exec project-backend-redis-1 redis-cli flushall`),
  remove `generated/` and `var/cache/`, then compile. A plugin on the JSON
  serializer causes exactly this, because bootstrap uses it before any
  interceptor exists.

## Known gaps and deviations

- Price rows are served only when the display currency is the base currency,
  catalog prices exclude tax and are displayed excluding tax, and fixed product
  taxes are off. Other setups fall back to core.
- Grouped children with required customizable options are not excluded from
  the grouped range as core's associated products collection does. A fixed
  bundle with customizable options falls back to core, which adds their price
  range. A percent bundle selection is a percent of the bundle's regular
  price; core applies a catalog rule on the bundle first.
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
