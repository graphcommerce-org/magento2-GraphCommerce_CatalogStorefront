# GraphCommerce_CatalogStorefrontProductFrontend

Rendered catalog pages built from documents, for a theme that renders them. Off by default: Catalog > Catalog > Catalog Storefront Document Store > Serve Product Listings From Documents, per store view.

- The listing collection is marked at the layer's item collection provider, and only a marked collection is hydrated, so child, related and bundle collections stay on core.
- The collection's own select still runs for the ids, the order and the price index columns; the attribute load is what the documents replace.
- A page with one product without a document loads from the database, with the ids logged.
- `X-Catalog-Storefront: documents|core` under the storefront key picks the path per request, and the path is part of the page cache id.
- `bin/magento catalog-storefront:parity:listing <base url>` renders the pages of `dev/parity/listing-pages.txt` on both paths and compares them line by line.
