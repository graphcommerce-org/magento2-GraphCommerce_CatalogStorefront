# GraphCommerce_CatalogStorefrontDownloadableGraphQl

Downloadable products in GraphQL from the documents.

- `downloadable_product_links` and `downloadable_product_samples` from the option slice.
- Native `price_range` loads `links_purchased_separately` as a dependency. Magento's
  price provider needs that flag to add separately purchased link prices to the
  maximum. The same price selection must return the same amount whether or not
  the client also requests the flag. This corrects the native hydration path;
  document hydration already includes the link prices.
