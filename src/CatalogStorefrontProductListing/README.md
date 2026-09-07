# GraphCommerce_CatalogStorefrontProductListing

Rendered product listing pages served from documents, for stores on a Luma or Hyvä theme rather
than GraphQL. Off by default: Catalog > Catalog > Catalog Storefront Document Store > **Serve
Product Listings From Documents**, per store view.

- `CollectionFlag` marks the listing collection at `ItemCollectionProvider::getCollection()`.
  `ListingHydration` acts only on a marked one, so a configurable's children, related products and
  bundle selections — all `Product\Collection` subclasses — are out of reach by construction rather
  than by a list of names. ElasticSuite's category layer is a virtualType of that provider, so both
  layers are covered.
- The saving is `_loadAttributes()` returning immediately on an empty `_itemsById`. The collection's
  own select still runs unreduced: its joins carry `minimal_price` and `max_price`, which are not in
  the document and which `isChildProductsOfEqualPrices()` needs, and which an `ORDER BY` on price or
  position refers to.
- One product without a usable document falls the **whole page** back to the database. A plugin
  cannot populate `_itemsById`, so a row-by-row merge would leave the fallback rows bare. The miss
  is logged with ids: all of them means the store code or the cluster, a few means the feeds are
  behind.
- A request carrying `X-Catalog-Storefront-Key` may name its path with `X-Catalog-Storefront:
  documents|core`, the same header the GraphQL side reads. The path is part of the page cache id, so
  such a request can never fill an ordinary visitor's cache slot.

## Parity gate

```bash
bin/magento catalog-storefront:parity:listing https://shop.example
```

Renders every page in `dev/parity/listing-pages.txt` both ways in one run and compares them line by
line, with form keys and Hyvä's uniqid element ids normalised away. Non-zero exit on any difference,
so it can gate a merge. `--pages` a different list, `--dump` keeps both renders, `--warm` renders
per path first, `--host` when the base URL is not the store domain.

The shipped list covers a configurable-heavy category, the same at 36 per page, a simple-product
category, and a search result — search shares the seam but not the code path, and browsing never
exercises it.

## What this does not speed up

Hyvä caches each rendered card (`ProductListItem::renderItemHtml()`, 3600s). On a cache hit none of
this runs. The gain is on misses — cold caches, invalidated products, deep pagination, cache warming
— and measurements should report warm and cold separately.
