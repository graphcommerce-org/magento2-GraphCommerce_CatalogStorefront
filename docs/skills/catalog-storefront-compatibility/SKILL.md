---
name: catalog-storefront-compatibility
description: Make a Magento module serve its catalog data through the GraphCommerce Catalog Storefront document store, and prove it with the parity gate.
---

# Catalog Storefront compatibility

The Catalog Storefront serves catalog GraphQL from one document per product and
store view, built by the commerce-data-export feeds, plus one document per
category, attribute, rating and review. A request reads documents and nothing
else. Your module is compatible when every field it adds or changes is answered
from a document, and the gate below proves it.

## The rule

A GraphQL request MUST NOT run a SQL lookup for catalog data. Index time (feed
providers, writers, document fields) MAY run SQL. When a document cannot answer
a field, hand the call to the core resolver; never read the database yourself on
the request path. Nothing derived from catalog data may live longer than one
request: a per-request memo implements `ResetAfterRequestInterface`.

## Decide what you have

1. **A product or category attribute.** Nothing to build. Product documents
   carry the raw store view value of every attribute of the attribute set
   (`customAttributes`), the model builder puts them on the product model, and
   the dynamic schema fields and `custom_attributesV2` read them. Verify with
   the gate.
2. **A GraphQL field whose resolver reads the database.** Two halves: put the
   data on the document at index time, serve the field from it at request time.
3. **Data that grows without bound per product** (reviews, questions, stock per
   source). Not a key on the product document: a partial update replaces an
   array as a whole, and every update rewrites the whole document. Make it an
   entity of its own with declared fields, answer aggregates by query.
4. **A plugin or preference on a core catalog resolver.** Keep it, but it runs
   on a model built from a document. Any load it triggers is a SQL lookup the
   gate reports.

## Index time

Paths are relative to `src/CatalogStorefront` unless another module is named;
copy the pattern, not the code.

- **Add a field to an exporter record.** An `et_schema.xml` provider on the
  record (`Product`, `Category`, `StockItemStatus`, `Review`, ...). A provider
  receives the `using` fields of every row of the batch and MUST return every
  `using` field back on each row it emits, or the exporter drops the batch with
  "child provider: <class>". Examples: `Model/DataExporter/Provider/AttributeSet`
  (one scalar), `Model/DataExporter/Provider/CustomAttributes` (a repeated
  record). A feed row re-exports only when its hash changes: after an
  `et_schema.xml` change, truncate the feed table and reindex the feed.
- **Fix what an exporter provider emits.** A plugin on the provider class, in
  `Plugin/DataExporter/`. Example: `Plugin/DataExporter/TierPricePercent`.
- **Compute a product document field from the feed row.** Implement
  `GraphCommerce\CatalogStorefrontApi\Document\ProductDocumentFieldInterface`
  and register it under `fields` on `Model\Document\Writer\Products`. Example:
  `Model/Document/Field/ImageUrls`. Runs under the store view's environment
  emulation; SQL allowed.
- **Write a feed of your own into the documents.** Implement
  `GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface` and register
  it under `writers` on `Model\Document\Delivery`, keyed by the feed name. A
  writer patches keys on the product documents through
  `ProductDocumentStorageInterface::upsert` (objects merge, arrays are replaced)
  or writes an entity of its own through `MetadataDocumentStorageInterface`.
  Examples: `CatalogStorefrontInventory/Model/Document/Writer/Stock` (a key per
  product), `CatalogStorefrontReview/Model/Document/Writer/Reviews` (an entity).
- **Declare the fields of an entity you query on.** Register them under
  `mappings` on `GraphCommerce\CatalogStorefrontApi\Storage\EntityMappings`,
  entity name to field name to `keyword`, `integer`, `float`, `boolean` or
  `date`. Only declared fields filter, sort and aggregate; every other field
  stays in the source. Example: `CatalogStorefrontReview/etc/di.xml`. A mapping
  change needs the index dropped and the feed re-exported.

## Request time

- **A field derived from the model and the document.** Implement
  `GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface` and
  register it under `prefillers` on `CatalogStorefrontGraphQl\Model\DocumentHydration`.
  It receives the page's models and documents and a `PrefillRequest` (store,
  group key, `selects()` for the fields the query asks for, `priceData()` for
  composites) and returns values per product id. List the fields under
  `prefilledFields` on `CatalogStorefrontGraphQl\Plugin\Query\ReuseSchema`, per
  GraphQL type or interface: the executor then returns the value without a
  resolver call, and falls back to the core resolver when nothing was filled.
  Add the fields to `baseFields` when the model answers them without the
  `customAttributes` slice, or map them to the document keys they need under
  `fieldDocumentKeys`, so a listing fetch leaves the other keys out. Example:
  `CatalogStorefrontInventoryGraphQl/Model/Prefill/Stock`. Prefillers run in
  di.xml order; a later one may read what an earlier one filled from the model
  under `PrefillerInterface::KEY`.
- **A field that needs other documents or arguments.** An `aroundResolve`
  plugin on the core resolver. Read the document from
  `$value['model']->getData(HydrationInterface::DOCUMENT_KEY)`; without one,
  or when an argument is one the documents cannot answer, call `$proceed`.
  Fetch related documents through `HydrationInterface::documents()` and build
  prefilled models through `models()`; query an entity through
  `MetadataDocumentStorageInterface::find()` and `stats()`. Wrap the work in
  `try`/`catch (\Throwable)` that logs and calls `$proceed`. Merge the
  selections of every `$info->fieldNodes` entry: a page selects a field through
  several fragments and each is a node. Examples:
  `CatalogStorefrontGraphQl/Plugin/Resolver/CategoryListFromDocuments`,
  `CatalogStorefrontReviewGraphQl/Plugin/Resolver/ReviewsFromDocument`.
- **A price range for a product type.** Implement
  `GraphCommerce\CatalogStorefrontApi\Read\PriceRangeInterface` and register it
  under `ranges` on `Model\Read\PriceRanges` by type id. Example:
  `CatalogStorefrontGroupedProduct/Model/Read/GroupedRange`.

## Module shape

Follow the cut of core: a base module (feed patch-ups in `Model/DataExporter`
and `Plugin/DataExporter`, writers and fields in `Model/Document`, shared read
services in `Model/Read`) and a `*GraphQl` module (`Model/Prefill`,
`Plugin/Resolver`). The base depends on `GraphCommerce_CatalogStorefront`, the
GraphQl module on `GraphCommerce_CatalogStorefrontGraphQl`, and both on the
core modules they plug into, so a shop without them leaves yours disabled.

## Prove it

1. Write a parity query that selects every field you serve, for products of
   every type you touch, as `dev/parity/queries/NN-<name>.graphql` with literal
   arguments (no variables). For a GraphCommerce page, resolve its fragments
   into the query; see `21-` to `24-` for the shape.
2. Re-export what changed: truncate the feed table (`cde_products_feed`,
   `cde_categories_feed`, `inventory_data_exporter_stock_status_feed`, ...) and
   reindex the feed; after a mapping change, drop the entity's index first.
3. Compile after di.xml changes, flush the host and the worker caches, restart
   the worker, reload the host php-fpm masters after a module link changes.
4. Run the gate against the worker's own host name with the attribution module
   enabled in the worker:

   ```sh
   GC_WORKER_CONTAINER=<worker container> \
     php packages/magento2-GraphCommerce_CatalogStorefront/dev/parity/run.php https://<worker host>/graphql
   ```

   It runs every query on the core path and the document path, warms each
   query twice, and reports per query: `PASS`, `DIFF` with the differing fields,
   `SQL` with the statements the document path ran (a failure), `WRITE` for a
   write (printed, allowed). `GC_PARITY_DUMP=<dir>` keeps both responses.
5. A `DIFF` is yours to explain: match core byte for byte, or document the
   deviation in CLAUDE.md under known deviations with the reason. A `SQL` line
   means a lookup escaped: find the resolver in the statement's stack through
   `dev/attribution/run.sh`, which prints the resolver classes and their SQL
   per request.
6. Poison test one field: edit its value in the document, request it, see the
   edited value. The gate proves equality, not that the document is the source.

## Checklist

- No SQL on the request path, fallback to `$proceed` only.
- Every `using` field returned by every provider row.
- Arrays on the product document bounded per product; unbounded data is an entity.
- Fields registered: `prefilledFields`, and `baseFields` or `fieldDocumentKeys`.
- Per-request state resets through `ResetAfterRequestInterface`.
- A parity query committed next to the code, gate green, deviations documented.
