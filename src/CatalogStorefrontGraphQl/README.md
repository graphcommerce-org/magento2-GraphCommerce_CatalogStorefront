# GraphCommerce_CatalogStorefrontGraphQl

Catalog GraphQL served from the documents.

- The product listing and the product lookup rebuilt from documents, one search engine round trip per page.
- The prefillers for the product fields core resolves from the model alone: ids, images, dates, prices, tier prices, websites.
- The resolver plugins for categories, media gallery, URL rewrites, custom attributes and linked products; the layered navigation from the category and attribute documents.
- The schema reuse and the once-per-process validation for the FrankenPHP worker.
