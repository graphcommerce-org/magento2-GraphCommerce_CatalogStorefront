# GraphCommerce_CatalogStorefrontGraphQl

Catalog GraphQL served from the documents.

- The product listing and the product lookup rebuilt from documents, one search engine round trip per page, at most 2 000 items per page on either path.
- The prefillers for the product fields core resolves from the model alone: ids, images, dates, prices, tier prices, websites.
- The resolver plugins for categories, media gallery, URL rewrites, custom attributes and linked products; the layered navigation from the category and attribute documents.
- The customer claims in the token, so a signed-in request reads no customer row.
- `bin/magento catalog-storefront:parity <endpoint>`: every query of `dev/parity` on both paths, compared.
