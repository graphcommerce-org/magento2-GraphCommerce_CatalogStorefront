# GraphCommerce_CatalogStorefrontDownloadableFrontend

The links and samples of a downloadable product page served from documents, for a Luma or
Hyvä theme, under the `serve_pdp` setting of GraphCommerce_CatalogStorefrontProductFrontend and
its `X-Catalog-Storefront` header. Frontend area only.

- [`Plugin/LinksFromDocument`](Plugin/LinksFromDocument.php) answers `getLinks()` and
  `getSamples()` of the downloadable type from the document's downloadable `optionsV2` values and
  its `samples`, and keeps them in the product's `downloadable_links` and `downloadable_samples`,
  which core's type reads first. A link has a sample when the feed gives it an `infoUrl`.
- It registers `downloadable` with the option types the product page plugin supports.
- A link UID or a sample URL that names no id stops the page with `DocumentReadException`.

```sh
bin/magento catalog-storefront:parity:detail https://shop.example
```
