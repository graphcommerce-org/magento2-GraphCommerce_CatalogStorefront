# GraphCommerce_CatalogStorefrontSearch

What the package changes about core's catalog search, each behind a setting under Catalog > Catalog > Catalog Storefront Search, with no dependency on the document store.

- The listing tie-break sorts on a product id field of the fulltext document instead of a painless script over every match.
- One search field name lookup per attribute code per request.
- Search term recording off by default.
- A result window that covers the catalog, so a deep listing page is one query instead of a walk in windows of 10 000 hits.
