# GraphCommerce_CatalogStorefront

The base: feeds in, documents out, models for any frontend.

- `Model/DataExporter`: patch-ups of commerce-data-export, the fields the core feeds lack.
- `Model/Document`: the feed delivery, the writers for the products, prices, categories and attributes feeds, the composite links, the image URLs.
- `Model/Read`: product documents to product models, the price ranges per product type, the attribute documents.
- The configuration under Catalog > Catalog > Catalog Storefront Document Store.
