# GraphCommerce_CatalogStorefrontConfigurableProductFrontend

A configurable product on a rendered Luma or Hyvä page, where core pays queries per child.
Every seam acts on a product that carries a document, so it serves a listing card and a
product detail page alike. It depends on Magento_ConfigurableProduct and
GraphCommerce_CatalogStorefrontProductFrontend.

- `getUsedProducts()` builds the children from the parent's `variantIds` in one read and
  sets each child's catalog rule price and empty tier prices. Missing prices and populated
  tier prices raise `DocumentReadException`.
- `getConfigurableAttributes()` builds the super attribute models from
  `configurableOptions`. The swatch block asks for them before the block cache, so every
  render pays this one.
- The lowest price options provider builds a listing card's cheapest child per tax class from
  the page's child price ranges, so the card prices without its variant documents. A product
  detail page, a store with fixed product taxes and a product without a range pick the
  cheapest child by final price and by regular price from `priceIndex`.
- `getParentIdsByChild()` answers from the `parentIds` of a card's document or of a card
  variant's document. Core's identities plugin asks it for every simple product whose cache
  tags a card collects, a query per product on every render, also on a block cache hit.

Each seam hands a product without a document to core.
