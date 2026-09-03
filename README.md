# GraphCommerce_CatalogStorefront

A catalog read model for Magento 2 / Mage-OS. The module stores one denormalised
product document per store view in the search engine you already run (OpenSearch or
Elasticsearch), and serves the catalog GraphQL `products` query from those documents.
The stock EAV path stays intact as the fallback.

## How it works

The search engine already selects and orders product ids. The expensive part of a
catalog GraphQL request is what happens after that: a collection load plus a chain of
per-product lazy loads (media gallery, category ids, price info). Measured on Mage-OS
3.4 with sample data, that is 77 queries for a 24-product page.

This module replaces that hydration step:

1. **Index.** The `graphcommerce_catalog_documents` indexer extracts each product with
   `HydratorPool::extract()`, the same mechanism core uses for the GraphQL resolver
   cache, and bulk-writes the flat documents into a versioned index behind an alias
   (`<prefix>_gc_products_<storeId>`). Full rebuilds write a new index and swap the
   alias. Change tracking is standard mview.
2. **Read.** A plugin on `ProductSearch::getList` fetches the documents for the ids the
   search returned, rebuilds product models from them with `Hydrator::hydrate()`, and
   hands those models to the unmodified resolvers. Same page: 25 queries instead of 77.
3. **Fallback.** Any miss (no index yet, a document missing, any exception) falls back
   to the original code path for that request.

The documents live in a `dynamic: false` index: nothing is analysed or indexed, the
engine is used as a fast JSON store addressed by entity id.

## Install

Not yet on Packagist. For now, clone and map it:

```json
"repositories": [
    { "type": "path", "url": "../magento2-GraphCommerce_CatalogStorefront" }
]
```

```bash
composer require graphcommerce/module-catalog-storefront:@dev
bin/magento module:enable GraphCommerce_CatalogStorefront
bin/magento setup:upgrade
```

A checkout in `app/code/GraphCommerce/CatalogStorefront` works too.

## Use

```bash
bin/magento indexer:reindex graphcommerce_catalog_documents
bin/magento indexer:set-mode schedule graphcommerce_catalog_documents
bin/magento config:set graphcommerce/catalog_storefront/serve_reads 1
bin/magento cache:flush config
```

`serve_reads` gates only the read path. With the flag off the indexer still builds
documents and every request uses the stock path, so you can build the index first and
flip reads per store view.

## Known deviations from the stock path

- Downloadable products with links purchased separately return a `price_range`
  whose maximum includes the link prices. The stock collection path does not load
  `links_purchased_separately` and returns 0 for these products. The document path
  matches what core computes for a fully loaded product and what the price index
  stores.

## Scope and limits

- Serves the `products` GraphQL query. Cart, checkout, customers, orders and the admin
  keep the normal path.
- Documents are eventually consistent: an admin save lands after the mview cycle.
- Final price is still computed at read time (catalog price rules are an index, not an
  attribute), which costs roughly one query per product. Serving price from documents
  is the next milestone and needs a price-feed join.
- Variation attributes of configurable children come from the child documents, which
  are indexed like any other enabled product.

## Background

The design recovers the storage idea of Magento's cancelled 2021 Storefront
Application (`magento/catalog-storefront`) and applies it in-process, where FrankenPHP
worker mode keeps the resolvers hot. The measurements the numbers above come from are
reproducible with three standalone probe scripts against any 2.4.x install.
