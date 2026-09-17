# GraphCommerce_CatalogStorefrontConfigurableProductGraphQl

Configurable products in GraphQL from the documents. It plugs into
Magento_ConfigurableProductGraphQl.

- `configurable_options`: the response shape is built from the `configurableOptions` field
  the index-time provider stored, with the uids and an image swatch's thumbnail URL derived
  at read time.
- `variants`: the children by `variantIds`, enabled and, unless the store shows
  out-of-stock products, salable. A variant's attributes resolve from `configurableOptions`
  by attribute id.
- `configurable_product_options_selection`: the option values and the media of the
  selection, from the same field and the child documents.
