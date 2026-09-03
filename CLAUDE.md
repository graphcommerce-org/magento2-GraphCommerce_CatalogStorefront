# GraphCommerce_CatalogStorefront

Catalog read model: feed documents in OpenSearch serve the catalog GraphQL read path.

## Rules

- A GraphQL request MUST NOT run any custom SQL lookup. Every piece of data a
  request needs MUST come from the OpenSearch document. Index-time feed
  processing (the `ExportFeedInterface` implementation, run by `indexer:reindex`)
  MAY use SQL to assemble documents; the request path may not.
- The read path MUST fall back to the core resolver whenever the document lacks
  what a field needs, never to a database read of its own.
- Parity is the gate: `dev/parity/run.php <endpoint>` MUST stay green before a
  change ships. Add a query for every field a new plugin serves.

## Boundary note

`ProductModelBuilder` decodes feed labels back to ids for status, visibility, tax
class and select-attribute options using process-cached metadata maps (EAV and
tax config, loaded once per process, not per product or per request). These are
metadata, not catalog data. If this must also leave the request path, carry the
ids in the feed instead of the labels.

## Shape

- Write: `LocalExportFeed` routes feed slices into one document per store view
  (products = base, prices = `prices.<group>`, inventory = `stock`, variants =
  `variantIds` on the configurable parent).
- Read: `ServeSearchFromDocuments` / `ServeFilterFromDocuments` rebuild product
  models from documents; per-field plugins (`Plugin/Resolver/*`) serve
  media_gallery, url_rewrites and max_sale_qty from the document. price_range
  runs on the core resolver over the rehydrated model.
- The product index mapping is `dynamic: false`: every field is stored in
  `_source`, none is mapped. Rich configurable documents otherwise exceed the
  1000-field mapping limit and their writes fail silently.

## Known gaps

- **price_range from documents.** Deferred. The price feed carries per
  customer-group rows with `catalog_rule` and `special_price` discounts, and the
  guest discount can sit under a hashed group code rather than `"0"`. Serving it
  correctly needs full customer-group and discount handling, verified against the
  harness. Core computes it correctly over the rehydrated model for now.
- **Downloadable links purchased separately.** `links_purchased_separately` and
  the price-range maximum differ from stock; the builder does not rebuild
  `downloadable_product_links` from the feed `optionsV2`.
- `variantIds` currently has no read consumer; it is the foundation for the
  deferred configurable price_range and for the variants / configurable_options
  fields.
