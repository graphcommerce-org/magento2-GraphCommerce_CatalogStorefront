# GraphCommerce_CatalogStorefront

Catalog read model: feed documents in OpenSearch serve the catalog GraphQL read path.

## Layout

One composer package, one git repository, one Magento module per directory
under `src/`, named as the module. The cut follows core: a base module holds
what any frontend can use (feed patch-ups, document writers, model builder,
price ranges, metadata readers), its `GraphQl` twin holds the resolver
plugins, the prefillers and the schema plugins. A Hyvä or Luma integration
adds `*Frontend` modules next to the `*GraphQl` ones and reuses the base
modules. Each module depends only on the core modules it plugs into, so a
shop without one of them leaves that module disabled and the DI compile still
passes.

- `CatalogStorefrontApi`: the contracts any frontend codes against.
  `Storage\ProductDocumentStorageInterface` and
  `Storage\MetadataDocumentStorageInterface` (the document stores, entity name
  per call for the metadata feeds), `Document\FeedWriterInterface` (writes one
  feed's rows), `Document\ProductDocumentFieldInterface` (a product document
  field computed at index time), `Read\ProductDocumentsInterface` (documents
  and models by id, DOCUMENT_KEY, the lazy composite price data),
  `Read\DocumentContext` (store, group key, composite price data) and
  `Read\PriceRangeInterface` (the range of one product type), and
  `Storage\EntityMappings` (the fields a metadata entity declares for
  filtering, sorting and statistics).
- `CatalogStorefrontGraphQlApi`: the GraphQL contracts.
  `Read\PrefillerInterface` (fills fields on the product value, KEY),
  `Read\PrefillRequest` (a DocumentContext plus the selected fields),
  `Read\HydrationInterface` (models prefilled for a query) and
  `Parity\JudgeInterface` (a verdict of the parity gate, di.xml `judges` on
  the command).
- `CatalogStorefrontOpenSearch`: the storage interfaces on OpenSearch:
  `Model/Client` (core's OpenSearch client from the engine resolver, so the
  document store shares the search engine connection; one request per
  method; the id travels in the source under `id`; `updateLists` changes id
  lists inside the store with a painless script, so parallel writers cannot
  lose each other's change), `Model/Index` (one index per entity and store
  view behind two aliases, `<prefix>_<entity>_<store view>` for the reads and
  its `_write` twin for the writes, created on first write with the entity's
  `EntityMappings` fields and nothing else mapped; `stage` puts a fresh index
  behind the write alias, `promote` moves the read alias to it and deletes the
  old one) and the two stores.
- `CatalogStorefront`: the base. Feed delivery and the writers for the
  products, prices, categories and attributes feeds, the composite links, the
  image URL field, the exporter patch-ups the core feeds need, the model
  builder, `ProductDocuments`, `AttributeDocuments`, the
  configuration (`Model/Config`: storefront indexing, serve GraphQL, the
  storefront key, search term recording; the group sits under Catalog >
  Catalog in the admin; `Model/Config/Backend/Key` generates the key on a
  save with the field empty), `Model/StorefrontKey` (whether the request
  carries the key in `X-Catalog-Storefront-Key`), `Model/Strict` (the fallback
  report of a keyed request), `Model/Feeds` (the feeds per entity, for the
  rebuild and status commands), `Plugin/Customer/PricesFeedOnNewGroup` (a new
  group truncates the prices feed table and invalidates its indexer) and the
  plugins on non-GraphQL core: product links, the layer price step, the single
  price range mode, the search field name memo, salable.
- `CatalogStorefrontGraphQl`: `Model/Mode` (the request's path), `DocumentHydration`
  with the prefiller list, the product prefiller, the listing data
  provider plugins, the resolver plugins for categories, media gallery, URL
  rewrites, custom attributes and linked products, the layered navigation
  plugins, the prefilled field routing and the fallback report on the query
  processor, the cache factor of the path, the parity console command, and the
  request plugins (`Plugin/Request`, `Plugin/Token`, request-scoped, no
  process memory): `UserTokenMemo` reads the bearer token
  once where core reads it three times (the request validator twice, the
  user context once), and the JWT reader runs before the opaque token reader
  so a storefront token skips the token table lookup that misses;
  `UserTokenValidateMemo` validates the token once where each read validates
  again (the revocation check is a table read because only revoked entries
  are cached); `CustomerClaimsIntoToken` puts the group (`gid`) and the
  website (`wid`) into a customer JWT when it is issued, `Model/Request/
  CustomerClaims` reads them back, and `CustomerContextFromToken` and
  `CustomerGroupFromToken` seed the context, the session and the http context
  from the claims where core loads the whole customer with addresses, region
  and newsletter status and reads the group column twice (a token without
  the claims goes to core); `RevokeOnGroupChange` revokes a customer's tokens
  when a save moves the customer to another group, so the group claim never
  outlives the group; `CustomerIdCheckFromToken` answers the session's id
  check from the token where core loads the customer to prove the id. A
  signed-in catalog request reads the revoked table, the address join and
  the rates of the product tax classes, nothing else about the customer.
- `CatalogStorefrontPrice` / `...PriceGraphQl`: `DisplayPrice` (a base currency
  price before tax, as the documents hold it, converted to the display
  currency and taxed through core's tax service for the request's group and
  destination: display incl, excl or both, catalog prices incl tax,
  cross-border trade and the tax classes follow core config; fixed product
  tax falls back), `PriceRanges` (di.xml `ranges`, by type id; the type
  modules add theirs) / the prices prefiller with its fields and routes (a
  type GraphQl module whose prefiller rewrites a price field sequences after
  `PriceGraphQl`, so the prices prefiller runs first), and
  `CustomerAddressColumns`: the tax rate request gets a signed-in customer's
  default address as one join read of its three tax columns, held for the
  request because every taxed amount builds its own rate request, and the tax
  class from the session's group, where core loads the customer twice.
- `CatalogStorefrontWorker`: what a FrankenPHP worker keeps between requests,
  each memo under a generation (`Model/Generation`: a token in the cache with
  the config tag; `Model/Memo`): the kept schemas per query shape, the validated
  documents, the deployment config check, the guest cache id tax factor, the
  guest tax rates, the customer group and the currency rate lookups. Nothing
  is keyed by customer: a signed-in customer's request carries its own
  address and stays with core's per-request caches.
  A cache flush or config cache clean lifts every memo; a save through the tax
  rule, rate and class repositories, the group repository, or the currency
  rate resource bumps the tax or currency
  generation (`Plugin/Bump`). Not needed under php-fpm.
- `CatalogStorefrontExplorer`: the Catalog switcher (Default, Documents,
  Database) of the MageOS_GraphQLAdminHtml explorer, through its
  `headerSwitchers` block argument; `Model/Switcher` builds it at page load so
  the Documents and Database options send the storefront key with the path.
- `CatalogStorefrontInventory` / `...InventoryGraphQl`: the stock feed writer
  (a stock's rows land on the store views of the websites it sells through)
  and stock item feed fields / the stock prefiller (the MSI source item
  management service says which types own a quantity).
- `CatalogStorefrontConfigurableProduct` / `...ConfigurableProductGraphQl`: the
  variants writer, the option value details patch-up, the configurable range /
  the configurable options document field (a GraphQL shape stored at index
  time), the variants, options and options selection resolvers.
- `CatalogStorefrontBundleProduct` / `...BundleProductGraphQl`: the bundle
  attribute feed fields, the bundle range / the price_details prefiller, the
  bundle items resolver.
- `CatalogStorefrontGroupedProduct` / `...GroupedProductGraphQl`: the grouped
  range / the deprecated price rewrite, the grouped items resolver.
- `CatalogStorefrontDownloadable` / `...DownloadableGraphQl`: the downloadable
  range registration / the links and samples resolvers.
- `CatalogStorefrontReview` / `...ReviewGraphQl`: the reviews and rating feeds
  made to export like the modern ones, the review date field, their writers
  (reviews become documents of their own, declared to the store through
  `EntityMappings`), the rating documents reader, the feed table schema / the
  reviews prefiller (one aggregation per page), the reviews resolver (a query
  per page).

Inside a base module the folders name the stage of the pipeline:

- `Model/DataExporter/` and `Plugin/DataExporter/`: patch-ups of
  commerce-data-export, run at index time, SQL allowed. `Provider/` classes are
  et_schema field providers that add fields to the exporter's existing records
  (nothing here is a new feed); `Processor/` classes make a legacy feed export
  like the modern ones; the plugins fix what an exporter provider leaves out.
- `Model/Document/`: the document store side. `Delivery` implements the
  exporter's `ExportFeedInterface` and hands each batch to the `Writer/` of its
  feed; `Field/` classes compute product document fields; `CompositeLinks`
  keeps the composite relations by id. A writer that merges into stored
  documents reads them through `stored()` and `storedBySku()`, the index that
  takes the writes, so a staged rebuild and a first build see what they wrote
  (the read alias points at the old index, or at nothing, until promote);
  `get()` and `findBySku()` are the request path's reads. A child outside the batch gets its
  parent id lists changed in the store (`updateLists`), the only
  read-modify-write a parallel feed thread could race, since the exporter
  partitions a feed's batches by source entity id and every other merge
  (prices per product, variant parents per child) stays inside one batch.
- `Model/Read/`: the request side any frontend shares: `ProductDocuments`,
  `ProductModelBuilder`, `PriceRanges` and `Price/`, `AttributeDocuments`.

Inside a GraphQl module: `Model/DocumentHydration`, `Model/Prefill/`,
`Plugin/Resolver/`, `Plugin/DataProvider/`, `Plugin/Layer/`, `Plugin/Query/`.

A module registers its parts in its own `etc/di.xml`: `writers` (by feed
name) on `Delivery`, `fields` on the products writer, `ranges` (by product
type id) on `PriceRanges`, and on the GraphQL side `prefillers`,
`priceFields`, `fieldDocumentKeys` and `baseFields` on `DocumentHydration`,
`prefilledFields` on `RoutePrefilledFields`. Prefillers run in di.xml order, so a later
one may rewrite what an earlier one filled. The composite price searches
(`ProductDocumentStorageInterface::priceData`, by `parentIds`,
`groupedParentIds` and `bundleParentIds`) and the fixed bundle type fold in
the model builder stay in the base: they are feed shape, not core module
classes.

Development install in this project: every module directory is linked into
`app/code/GraphCommerce/<module name>` (see README), the attribution module
from `dev/attribution/Module` next to them. An install from the package
registers all modules through composer autoload.

## Rules

- This package runs in the monolith: a GraphQL request SHOULD take everything
  it needs from the OpenSearch document, and MAY read the database where the
  document cannot answer or where a lookup is cheaper than carrying the data
  on the document. Index-time feed processing (the `ExportFeedInterface`
  implementation and feed plugins, run by `indexer:reindex`) assembles the
  documents and uses SQL freely. A deployment whose read side has no catalog
  database turns the SHOULD into a MUST NOT, so a lookup added here MUST be
  replaceable by a document field. The statements a request runs show in a
  MageOS_Profiler trace; this package records none itself.
- The read path MUST fall back to the core resolver whenever the document lacks
  what a field needs, never to a database read written into a document plugin.
- Parity is the gate: `bin/magento catalog-storefront:parity <endpoint>` MUST
  stay green before a change ships. It sends the storefront key (saved once
  in the configuration; `config:set catalog/storefront_documents/key ""`
  generates one) and picks each request's path with the `X-Catalog-Storefront`
  header; the response extensions carry the document path's fallbacks. Run it
  against the worker's own host name
  (`https://worker.localhost.reachdigital.io/graphql`; the backend host name
  goes to the host PHP-FPM). Every query runs twice unjudged first, and a
  failing query is requested again up to `--attempts` times (three), so the
  gate sees the steady state and not the memo fill of a cold worker thread. A fallback is printed, not failed: it is allowed when the
  document cannot answer, and the reason says whether that is so. Add a query
  for every field a new plugin serves. A poison test (edit a document in
  OpenSearch, see the change in the response) proves a field is live; the gate
  alone cannot. Product `sku` filters accept only `eq` and `in`; the gate fails
  a query that errors on either path. A query file sends its own request
  headers through `# @header Name: value` lines.
- Every fallback to core in a document plugin goes through `Model/Strict`:
  `fallback(self::class, reason)` where the document cannot answer,
  `exception(self::class, $e)` where the plugin failed (it logs a warning).
  The keyed report is what makes a silent fallback visible outside the gate.

## Boundary note

`ProductModelBuilder` sets the raw store view value of every attribute from the
document's `customAttributes` (option ids, tax class id, dates, prices as the
entity tables hold them) and decodes the feed's status and visibility labels
through static maps, so no metadata lookup is left on the request path; the
writer (`Model/Document/Field/ImageUrls`) resolves only the image URLs.

## Shape

- Write: `Model/Document/Delivery` hands each feed batch to its writer
  (`Model/Document/Writer/*`, di.xml `writers`) and then purges the cache tags
  of the products and categories the batch touched (di.xml `identities`, the
  `clean_cache_by_tags` event plus the app cache, as an indexer does), so the
  page, response and resolver caches hold nothing built from the replaced
  documents; together they build one document per store view
  (products = base, prices = the feed rows under `prices` with their customer
  `group` id (the feed names a group by the hash of its id; the writer maps it
  back, rows of an unknown group are dropped) plus `priceIndex`, a nested list
  with one entry per customer group holding the regular and final price with
  the fallback row resolved, in base currency before tax, inventory = `stock`,
  variants = `variantIds` on the configurable parent). Reviews are documents of their own (entity `review`, one per
  review and store view where it is visible, with the vote percents over the
  rating's scale); the product document carries nothing about them. `Model/ProductPrice` holds the price semantics both sides
  share. `Model/Document/CompositeLinks` keeps the grouped and bundle links by id
  (`groupedParentIds` and `bundleParentIds` on the children, the child id
  lists on the parent) from the products feed, which carries them by sku only,
  written from whichever side the feed delivers last. The feed folds a fixed
  bundle price type into the product type `bundle_fixed`;
  `Model/DataExporter/Provider/BundleAttributes` adds the sku and shipment type the
  exporter lacks. The categories feed lands as one category document per
  store view (`MetadataDocumentStorageInterface`, one entity name per feed, index `<alias>_<entity>_<store view>`, read by id or all
  at once). The product attributes feed lands the same way, keyed by attribute
  code and extended with the options in store view labels, the layer position
  and the filterable mode (`Model/DataExporter/Provider/AttributeOptions`,
  `AttributeLayer`); the rating metadata feed lands keyed by rating id,
  exported during indexing like the reviews (`Model/DataExporter/Processor/Ratings`,
  `etc/db_schema.xml` adds the modern columns to its table, di.xml gives its
  indexer the generic serializer). The reviews slice keeps each review's votes
  as rating id to value; the read side turns them into percents with the
  rating's value scale for the store view.
- Facet layer: `Plugin/Layer/CategoryFacetFromDocuments` builds the category
  bucket from the category documents (store tree membership by path, store
  view names, direct children and their activity for a category-filtered
  query); `RootCategoryFromStore` takes the root category id from the store
  model; `PriceRangeStepFromDocument` reads the current category's price step
  from its document; `Model/Layer/SingleRange` is the `single` price navigation
  step calculation (Stores > Configuration > Catalog > Layered Navigation): one
  range from the lowest to the highest price of the result, the bounds a price
  slider reads, where core's modes run two or three more search queries for
  intervals no headless frontend shows; `SingleRangeFromResponse` serves it
  from the extended stats the search response already carries, so the price
  bucket costs no follow-up query and no category step lookup. Both paths
  share the mode, so parity holds; the MySQL-era
  `Magento\Catalog\Model\Layer\Filter\Price` has no `single` algorithm and is
  not used with a search engine; `Plugin/CacheId/CustomerTaxRateMemo` keeps the response cache id's
  tax factor per store, group and customer per process;
  `Plugin/Deploy/ConfigChangeMemo` answers core's deployment config hash check
  once per process (a detected change is not kept, so an import lifts it). With
  these, a listing request runs no SQL.
  `etc/et_schema.xml` extends the feed with what the read side needs and the
  exporter lacks: link position, option admin label and textual swatch value
  (`Plugin/DataExporter/*`), and a reviews provider that exports during indexing
  (`Model/DataExporter/Processor/Reviews`; `etc/db_schema.xml` adds the modern feed
  columns to the exporter's own `catalog_data_exporter_product_reviews`).
- Read: `ServeSearchFromDocuments` / `ServeFilterFromDocuments` rebuild product
  models from documents through `DocumentHydration`. A listing page is one
  multi-search request: the documents by id with the heavy keys the query does
  not select left out (di.xml `fieldDocumentKeys`; `customAttributes` only
  when a non-base field is selected, the labelled `attributes` slice never),
  and, when a price field is selected, the composite price data:
  the configurable and the grouped price aggregation (terms on `parentIds`
  and on `groupedParentIds`, then nested into `priceIndex` filtered on the
  group, min and max of regular and final over salable and over all enabled
  children), the bundle selection documents by `bundleParentIds`, and the
  bundles' option slices. Every range is base currency before tax;
  each `PriceRangeInterface` turns it into display `Amount`s (value and tax)
  through `Model/Read/DisplayPrice`, which converts the way the price classes
  convert (the regular price unrounded, a discounted final price rounded) and
  taxes the way the tax adjustment taxes (core's tax service with the
  product's tax class, whenever catalog prices include tax or the display
  does), so the same request path answers every currency and tax display
  setup except fixed product taxes. The tax gate needs a rate at the store's
  default destination: the demo catalog's only rule is Michigan (region 33). A grouped range is the
  lowest regular and lowest final child price, as core takes them; a bundle
  range is `Model/Read/BundlePriceRange`, a port of core's bundle amount
  calculator over the option slice and the selection documents.
  The prefillers (`Model/Read/Prefill/*` of each module) then fill,
  on the product value the executor hands to child fields, the fields whose
  core resolvers only derive from the model and the document: uid, id,
  new_from_date, new_to_date, rating_summary, review_count, image, small_image
  and thumbnail (url through `Model/Read/ImageUrl`, label), stock_status,
  only_x_left_in_stock (salable quantity less the item's minimum, at most the
  configured threshold), quantity (the stock slice's quantity, null when the
  not-available message hides it), min_sale_qty and max_sale_qty (the stock
  slice carries the item's own minimum and sale quantities, null where it
  takes the configured value, `Model/DataExporter/Provider/StockItem`) and price_range
  (simple, virtual, downloadable, configurable). `RoutePrefilledFields`
  routes those fields (di.xml `prefilledFields`, per type or interface name)
  to the pre-filled value and to the core resolver when the parent carries
  none, so core-served products and what a document cannot answer keep the
  core path. A pre-filled field costs the executor a plain array read instead
  of a resolver call with its ResolveInfo object, argument validation and four
  plugins (about 4µs per call). Per-field plugins in `Plugin/Resolver/*` serve
  media_gallery, url_rewrites, configurable_options, downloadable
  links and samples, related, upsell and crosssell products (fetched by sku),
  configurable variants (the children by `variantIds`, enabled and, unless
  out-of-stock products are shown, salable; the variant attributes resolve
  from `configurableOptions` keyed by attribute id), bundle items and grouped
  items (from `optionsV2` and the child documents by `bundleChildIds` and
  `groupedChildIds`; the option list and label are pre-filled on the item,
  the product is the child's model, which the core product resolver takes as
  is), categories (`categoryData` is what the category product index holds:
  assignments and anchor ancestors, so only the store root is left out; the
  category documents are fetched once per request for the whole page and
  hydrated through core's category hydrator; the categories and categoryList
  queries resolve their id, uid, url key, url path and parent filters on the
  category documents, with breadcrumbs from the path and the active children
  nested to the depth the query selects, `Model/CategoryDocuments`), and custom_attributesV2
  (`customAttributes` on the document: the raw store view value of every
  attribute of the product's attribute set that has one, plus tier_price the
  way the load backend sets it, `Model/DataExporter/Provider/CustomAttributes`; the
  attribute documents give the visible non-static list by attribute id, the
  frontend input and the options, source model options included). Documents
  fetched outside a listing go through `DocumentHydration::documents` and
  `models`, which fetches the composite price data when the fields ask for a
  price range. `Plugin/SalableFromDocument` answers the configurable and bundle
  salability check from the inventory slice.
- Every index mapping is `dynamic: false`; the product index maps only `sku`,
  `type`, the parent id lists, `status`, `stock.isSalable` and the nested
  `priceIndex` (group, regular, final), declared in the base di.xml under
  `EntityMappings`, because requests filter or aggregate on them. Every field
  is stored in `_source`; rich configurable documents otherwise exceed the
  1000-field mapping limit and their writes fail. Wildcard `_source` filters
  are very slow: never.
- A listing page costs two OpenSearch round trips on the document path: the
  core product search, then the multi-search above. Writes are visible to the
  aggregation after the index refresh (one second by default); the fetch by id
  is immediate.
- Nothing derived from catalog data is held across requests. What a request
  needs beyond the documents it fetches per request:
  `Model/Read/AttributeDocuments` and `RatingDocuments` (the store view's
  attribute and rating documents; the facet labels come from them through
  `Plugin/Layer/AttributeOptionsFromDocuments` (GraphQl), the rating scale for the
  review percents), `Plugin/Search/FieldNameMemo` (search index field name
  per attribute code and context, the core mapper asks several times per
  attribute), `Model/Mode` and `Model/Strict` are request-scoped. Derivations
  that used to be memos are made by the writer instead:
  `Model/Document/Field/ImageUrls` puts the image media paths on the product
  document; the category price step travels on the category document. The
  state that does live for a process lifetime sits in the Worker module and
  derives from the query text, the deployment, the tax setup or the currency
  rates, not from catalog data; each memo lives under a generation, and a
  cache flush or config cache clean lifts it (see Layout).
  `etc/config.xml` turns on `dev/caching/cache_user_defined_attributes` so the
  EAV config serves user-defined attributes from cache instead of SQL.
- The Worker module's `ValidateOncePerProcess` validates a query document once
  per config generation and executes repeats with an empty rule set; its
  `ReuseSchema` keeps one built schema per query shape: Magento prunes every
  type to the names the query uses, so a schema belongs to that name set, and
  its type map is materialized at build time because the type registry resets
  between requests. A schema or config change reaches a worker at the next
  cache flush or config cache clean, which the admin config save does.
- `configurable_options` is built at index time (`Model/Document/Field/ConfigurableOptions` of the configurable GraphQl module,
  document key `configurableOptions`) and returned as is; its values carry the
  pre-filled `uid` and `swatch_data`.
- The GraphCommerce ProductList query (`dev/parity/queries/13-*.graphql`, all
  fragments and injections resolved) runs on the document path with no catalog
  SQL: only per-process metadata and bootstrap queries remain.

## Operations

- The FrankenPHP worker container has its own env file and its own Redis cache
  database. A host-side `cache:flush` or `config:set` never reaches it: flush
  inside the container (`docker exec project-backend-frankenphp-1 php
  bin/magento cache:flush`); that flush also lifts every worker memo, so no
  restart is needed after a config change. A benchmark or the parity gate
  picks the path per request with the `X-Catalog-Storefront` header under the
  storefront key (`config:show catalog/storefront_documents/key`), so no flag
  flips at all. The split cache cuts the other way too: a host-side reindex
  purges its cache tags in the host's Redis only, so the worker's core path
  serves a stale price until the container flush; the document path does not,
  the feed write purges through the same tags but the document itself is what
  it reads. `bin/magento catalog-storefront:status` shows the documents per
  store view against the products, and per feed the rows waiting for a retry
  and the indexer state.
- With immediate export, a full `indexer:reindex` of a feed skips rows whose
  feed hash is unchanged, so it does not repair a document store.
  `bin/magento catalog-storefront:rebuild [entities]` does the whole repair:
  it stages a fresh index per store view, truncates the feed tables
  registered for it (di.xml `feeds` on `Model/Feeds`), runs their indexers
  and promotes the fresh indices; the reads keep the old documents until then;
  about ten seconds for the demo catalog. By hand: stage or drop the index, truncate `cde_products_feed`,
  `cde_product_prices_feed`, `cde_product_variants_feed`,
  `inventory_data_exporter_stock_status_feed` and
  `catalog_data_exporter_product_reviews`, then reindex the products, stock,
  prices, variants and reviews feeds. A mapping change needs this too; a new
  customer group does it for the prices feed by itself. The metadata documents
  rebuild the same way: drop the category, attribute or rating index, truncate
  `cde_categories_feed`, `cde_product_attributes_feed` or
  `catalog_data_exporter_rating_metadata`, reindex that feed. The indices are
  `<prefix>_<entity>_<store view>`; `product` is an entity like the others.
- The parity set needs the fixed bundle `GC-BUNDLE-FIXED`
  (`dev/parity/fixtures/bundle-fixed.json`, POST it to `/rest/V1/products`
  with an admin token, then reindex stock, price, search and the feeds) and a
  second website with the store view `second` that sells every product
  (`php dev/parity/fixtures/second-store.php` from the Magento root, then
  `cache:flush` before `indexer:reindex`: the exporter and the search indexer
  read the store list from the config cache, and a reindex before the flush
  builds one store view; the dev shop has it): the
  demo catalog has only a dynamic bundle with required radio options. The
  harness fails a query that returns no product on either path, because a
  hidden product passed vacuously for hours before that check existed. The
  stock query (`18-*.graphql`) needs `24-WG01` at quantity 1 (source item and
  legacy stock item, then reindex `inventory`, `cataloginventory_stock` and
  the stock feed), so only_x_left_in_stock has a number to compare.
- After di.xml changes: `setup:di:compile`, `cache:flush` on the host and in
  the worker container, then restart the worker (new classes; a flush alone
  lifts the memos but not the loaded code). After a module link in
  `app/code` changes target, reload the host php-fpm masters too
  (`kill -USR2 $(pgrep -f 'php-fpm: master')`): with `opcache.revalidate_path`
  off, opcache keeps the resolved symlink target and the backend host name
  fails on the old registration path. After `et_schema.xml` changes
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
- `dev/os/get.os.http` holds the OpenSearch requests that show what the store
  built: the indices and aliases, the mappings, a product by id or sku, the
  slices still missing, the price and rating aggregations as the read side
  runs them, in the Dev Tools syntax the VS Code OpenSearch extension runs: a
  comment line above each request and `{}` under a request without a body,
  or the parser reads the next comment as the body.
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

- Prices are served in every currency and tax display setup except fixed
  product taxes, which fall back to core. A configurable or grouped range is
  taxed with the parent's tax class; core taxes the regular price of each
  child with the child's own class (its final price with the parent's). A
  dynamic bundle's selections are taxed with the child's class, as core does;
  the product document carries `taxClassId` for it. A composite range in a
  non-base currency converts the aggregated base prices; core converts and
  rounds each child, so a cent may differ where children mix discounted and
  regular prices. The tier price discounts are computed against the regular
  price before tax, as core's tier collection loads no tax class.
- Grouped children with required customizable options are not excluded from
  the grouped range as core's associated products collection does. A fixed
  bundle with customizable options falls back to core, which adds their price
  range. A percent bundle selection is a percent of the bundle's regular
  price; core applies a catalog rule on the bundle first.
- The feed exports the special price attribute without its from and to dates.
- `dev/parity/queries/19-*.graphql` selects every product field of the
  schema (`dev/parity/gen-all-fields.py <skus...>` generates it from introspection); the
  routable fields, `product_links` (its own query) and the bundle item's
  price range are left out. `--dump=<dir>` keeps both responses of every
  query for a closer look than the diff excerpt.
- `quantity` is the stock slice's quantity (the inventory stock, all assigned
  sources); core reads the legacy stock status, the default source only.
  `min_sale_qty` and `max_sale_qty` resolve the configured value without a
  customer group, as core's resolvers do. `custom_attributesV2` falls back to
  core for a filter on a property the attribute documents lack
  (`is_html_allowed_on_front`, `is_used_for_promo_rules`,
  `is_visible_in_advanced_search`, `is_wysiwyg_enabled`). Grouped items and
  bundle selections are not filtered on required customizable options or on
  stock, as core's collections do in some configurations. Variant attributes
  are listed by attribute id, the order core's super attribute index yields.
- A bundle item's `price_range` is the bundle's own range; core resolves a
  product loaded by the item's sku and answers something else for a dynamic
  bundle. The deprecated
  `tier_prices` read every tier as for all groups, which the price feed does
  not carry. `media_gallery_entries` ids and uids count from one per product;
  the feed carries no gallery value ids. A category's
  `product_count` is the count at export time. A grouped
  item's `qty` follows the link attribute; core answers 1 on some queries.
- rating_summary and review_count are one aggregation over the review
  documents per page; the reviews field pages by query. Both see a written
  review after the index refresh, one second by default.
- The metadata reads (attributes, ratings) page through the index in steps of
  a thousand up to OpenSearch's result window, 10000 documents per store view
  by default. The bundle selection search of a listing page caps at a thousand
  children.
- Intentional deviations. Core includes separately purchased downloadable link
  prices in the maximum price only when `links_purchased_separately` is loaded
  on the model, so its answer depends on the query; the document path always
  includes them. Core lists configurable options in an undefined order (no
  ORDER BY, it changes with the query plan); the document path lists them by
  position. Core cannot resolve an inline fragment inside a linked products
  selection; the document path can.
