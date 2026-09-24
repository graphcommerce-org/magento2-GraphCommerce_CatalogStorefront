# GraphCommerce_CatalogStorefrontProductFrontend

Rendered catalog pages built from documents, for a Luma or Hyvä theme. It plugs into
Magento_Catalog and names no theme class. Both settings are off by default, per store view,
under Catalog > Catalog > Catalog Storefront Document Store:
`catalog/storefront_documents/serve_plp` for the category and search listing pages and
`catalog/storefront_documents/serve_pdp` for the product detail page.

- [`Plugin/Listing/CollectionFlag`](Plugin/Listing/CollectionFlag.php) marks the collection
  the layer's item collection provider returns, and
  [`ListingHydration`](Plugin/Listing/ListingHydration.php) hydrates a marked collection
  only, so child, related and bundle collections stay on core. The select of the collection
  still runs: it carries the ids, the order and the `minimal_price` and `max_price`
  columns. The attribute load is what the documents replace. The same read carries the child
  price ranges of the page's composite products for the customer group, and
  [`Model/Read/ListingDocuments`](Model/Read/ListingDocuments.php) keeps the documents and the
  ranges for the cards.
- [`Plugin/Detail/ProductDocument`](Plugin/Detail/ProductDocument.php) builds the product of
  a `catalog_product_view` request from its document instead of loading it, so a
  configurable builds its children, its options and its price without a query. The cart,
  the wish list, an order, an indexer and the Admin share that repository and keep the
  database load.
- A page with one product without a document loads from the database, with the ids logged.
- `X-Catalog-Storefront: documents|core` under the storefront key picks the path per
  request, and [`Plugin/PageCacheIdentifier`](Plugin/PageCacheIdentifier.php) puts the path
  into the page cache id, so a keyed request fills no visitor's slot.

Two gates render both paths and compare them line by line, with the form key, the uniqid
element id suffixes and the private content stamps normalised:

```sh
bin/magento catalog-storefront:parity:listing https://shop.example
bin/magento catalog-storefront:parity:detail https://shop.example
```

`catalog-storefront:parity:detail` fails a page that renders no add to cart form, because
two error pages compare equal. A theme module adds its own comparison under `perRender` on
either command. Both gates read the rendered HTML: read the fallbacks in the log or the
OpenSearch count of a profiler trace to prove that the document path served.

From inside the application container the store domain does not resolve. Reach it as
`http://localhost` with `--host <domain>` and, behind a TLS proxy,
`--header "X-Forwarded-Proto: https"`.

A theme that caches its rendered cards, as Hyvä does for an hour, pays this off on the
misses: measure warm and cold apart.
