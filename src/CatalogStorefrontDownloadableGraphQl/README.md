# GraphCommerce_CatalogStorefrontDownloadableGraphQl

Downloadable products in GraphQL from the documents. It plugs into
Magento_DownloadableGraphQl.

- `downloadable_product_links` and `downloadable_product_samples` come from the option
  slice of the document.
- [etc/graphql/di.xml](etc/graphql/di.xml) makes `price_range` load
  `links_purchased_separately` on the core path. Core's price provider adds the price of a
  separately purchased link to the maximum only while that flag is on the model, so without
  the map the same price selection answers two amounts, one per query shape. The document
  path always holds the link prices.
