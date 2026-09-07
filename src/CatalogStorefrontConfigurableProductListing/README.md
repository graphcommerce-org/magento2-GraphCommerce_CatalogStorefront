# GraphCommerce_CatalogStorefrontConfigurableProductListing

The configurable card of a listing page from documents, where core pays queries per card.

- `getUsedProducts()`: the children from the `variantIds` of the parent document, in one read.
- `getConfigurableAttributes()`: the super attribute models from `configurableOptions`, built at index time.
- The lowest price options provider: the cheapest child by final and by regular price from `priceIndex`, core's provider for anything else.
- Each seam goes to core for a product without a document, and the children seam also when a listed variant is missing.
