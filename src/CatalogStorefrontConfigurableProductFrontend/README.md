# GraphCommerce_CatalogStorefrontConfigurableProductFrontend

A configurable on a rendered page from documents, where core pays queries per product. Each seam
acts on a product that carries a document, so it serves a listing card and a product detail page
alike.

- `getUsedProducts()`: the children from the `variantIds` of the parent document, in one read.
- `getConfigurableAttributes()`: the super attribute models from the `configurableOptions` of the document.
- The lowest price options provider: the cheapest child by final and by regular price from `priceIndex`, core's provider for anything else.
- Each seam goes to core for a product without a document, and the children seam also when a listed variant is missing.
