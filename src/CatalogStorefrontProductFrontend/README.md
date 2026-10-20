# GraphCommerce_CatalogStorefrontProductFrontend

Rendered catalog pages built from documents, for a Luma or Hyvä theme. It plugs into
Magento_Catalog and names no theme class. Both settings are off by default, per store view,
under Catalog > Catalog > Catalog Storefront Document Store:
`catalog/storefront_documents/serve_plp` for the category and search listing pages and
`catalog/storefront_documents/serve_pdp` for the product detail page.

- [`Plugin/Listing/CollectionFlag`](Plugin/Listing/CollectionFlag.php) marks the collection
  the layer's item collection provider returns, and
  [`ListingHydration`](Plugin/Listing/ListingHydration.php) hydrates a marked collection
  only. The select of the collection
  still runs: it carries the ids, the order and the `minimal_price` and `max_price`
  columns. The attribute load is what the documents replace. The same read carries the child
  price ranges of the page's composite products for the customer group, and
  [`Model/Read/ListingDocuments`](Model/Read/ListingDocuments.php) keeps the documents and the
  ranges for the cards. The cards' URL rewrites and the special price map of the price boxes
  come from them, so the list template runs no query.
- [`Plugin/Listing/FilterableAttributesFromDocuments`](Plugin/Listing/FilterableAttributesFromDocuments.php)
  lists the filterable attributes of the layered navigation from the attribute documents, with
  the attribute models of the EAV config. It reads every attribute document of the store view once
  per request, so the other labels of the page read nothing further.
- [`Plugin/OptionsFromDocuments`](Plugin/OptionsFromDocuments.php) answers `getAllOptions()` and
  `getSpecificOptions()`, and so `getOptionText()`, of a product attribute's table source from
  its attribute document ([`Model/Read/AttributeOptions`](Model/Read/AttributeOptions.php)): the
  labels of the attributes of a product page, of its secondary info and of the swatches, which
  core reads for every value. An attribute with a source model of its own and a read under an
  emulated area, as the attribute export makes, read the tables; an attribute without a document
  and a read of the admin labels stop the page.
  [`Plugin/Detail/ProductDocument`](Plugin/Detail/ProductDocument.php), once it builds the
  product page's product, reads the documents of every attribute of its attribute set in one
  request (`AttributeOptions::prime()`): the secondary info, the attributes table and the
  swatches then read none. The read carries every option of those attributes, so an attribute
  with very many options makes it large.
- [`Plugin/StoreLabelFromDocuments`](Plugin/StoreLabelFromDocuments.php) answers
  `getStoreLabel()` of a product attribute from its document: every attribute label a page
  shows, the attributes table, the swatches and options, the sort order and the layered
  navigation. Core keeps the store labels it read on the attribute for the life of the process,
  so under an application server a changed label shows only after a restart; the document follows
  the change through the attributes feed. A document without a label stops the page.
- [`Plugin/SwatchesFromDocuments`](Plugin/SwatchesFromDocuments.php) answers core's swatch
  helper (`getSwatchesByOptionsId()`) from the attribute documents: the swatches of a product
  page, of the cards of a listing and of its layered navigation. Core keeps the swatches it read
  by option id alone for the life of the process, so a changed swatch otherwise shows only after a
  restart, and a text swatch read for one store view answers the others. The helper knows the
  option ids only; `AttributeDocuments::optionsById()` finds them in the documents the request
  read, else in every document of the store view, read once. The swatches are on the documents
  through GraphCommerce_CatalogStorefrontSwatches, which this module requires.
- [`Plugin/Listing/TaxPriceFromRate`](Plugin/Listing/TaxPriceFromRate.php) prices a document
  product's amount with the rate of the request through the price module's `TaxPrice`, the
  arithmetic of the GraphQL path, instead of core's tax calculator per amount.
- [`Plugin/Listing/ProductUrlFromRequestPath`](Plugin/Listing/ProductUrlFromRequestPath.php)
  answers a document product's URL as the store's link base URL and the request path, without
  a URL builder per link. A product without a request path (the product page, a row of linked
  products) takes its own rewrite from the document's `urlRewrites` when no category applies, where
  core asks the url finder once per product.
- [`Plugin/Detail/ProductDocument`](Plugin/Detail/ProductDocument.php) builds the product of
  a `catalog_product_view` request from its document instead of loading it, so a
  configurable builds its children, its options and its price without a query. The cart,
  the wish list, an order, an indexer and the Admin share that repository and keep the
  database load.
- A missing product document stops the page with `DocumentReadException`.
- [`PricesFromDocument`](Plugin/Detail/PricesFromDocument.php) supplies customer group tier
  prices and catalog rule prices. [`CatalogRuleFromDocument`](Plugin/Detail/CatalogRuleFromDocument.php)
  applies document rule prices to frontend price events.
- [`BundleProductFrontend`](../CatalogStorefrontBundleProductFrontend) supplies bundle
  options and selections. [`GroupedProductFrontend`](../CatalogStorefrontGroupedProductFrontend)
  supplies grouped children and quantity validation. Both modules require the product and stock feeds.
- `X-Catalog-Storefront: documents|core` under the storefront key picks the path per
  request. [`Plugin/PageCacheOff`](Plugin/PageCacheOff.php) disables full-page
  caching for these requests, so each parity check renders the selected reader.
- `catalog/storefront_documents/cache_category_pages` (on by default, per store view) set to
  0 keeps category pages out of the page cache, Varnish and built-in alike, through the same
  plugin: every visit renders the page, and the blocks Varnish includes by ESI render inline.

Two gates render both paths and compare them line by line, with the form key, the uniqid
element id suffixes and the private content stamps normalised:

```sh
bin/magento catalog-storefront:parity:listing https://shop.example
bin/magento catalog-storefront:parity:detail https://shop.example
```

`catalog-storefront:parity:detail` fails a page that renders no add to cart form, because
two error pages compare equal. A theme module adds its own comparison under `perRender` on
either command. Document read failures stop the page. A profiler trace identifies its reads.

From inside the application container the store domain does not resolve. Reach it as
`http://localhost` with `--host <domain>` and, behind a TLS proxy,
`--header "X-Forwarded-Proto: https"`.

A theme that caches its rendered cards, as Hyvä does for an hour, pays this off on the
misses: measure warm and cold apart.
