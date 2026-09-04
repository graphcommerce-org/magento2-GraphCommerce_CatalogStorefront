# GraphCommerce_CatalogStorefrontOpenSearch

The document stores on OpenSearch.

- One index per store view for products, one per entity and store view for the metadata feeds, behind a blue/green alias.
- The connection comes from the `catalog-store-front` block of `app/etc/env.php`.
- Only the fields a request filters or aggregates on are mapped; every other field stays in the source.
