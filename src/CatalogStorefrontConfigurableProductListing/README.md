# GraphCommerce_CatalogStorefrontConfigurableProductListing

The configurable half of the listing page: the three things a configurable card asks for that
otherwise cost queries per card rather than per page.

| the card calls | core does | this does |
| --- | --- | --- |
| `getUsedProducts()` | a product collection per parent, every listing attribute, then media gallery and tier prices | one fetch by id, from the `variantIds` the parent document already lists |
| `getConfigurableAttributes()` | an attribute collection, its labels, then a query per super attribute | builds the models from `configurableOptions`, assembled at index time |
| `LowestPriceOptionsProviderInterface` | a UNION for the cheapest child per price type, then loads them | picks cheapest by final and by regular price out of `priceIndex` |

- `getConfigurableAttributes()` matters more than it looks: `Listing\Configurable::getCacheKey()`
  calls it *before* the block cache is consulted, so it is paid on every render, hit or miss.
- Both cheapest children are returned, not one. `ConfigurablePriceResolver` minimises the final
  price and `ConfigurableRegularPrice` the regular one, and per-variant discounts make those
  different children. Returning *every* child is also correct but not cheap — each child's
  `getPriceInfo()` runs its own tier price and catalog rule lookup.
- Each seam falls back to core when the product carries no document, and `getUsedProducts()` also
  when any listed variant is missing — a short option list is worse than a slow one.
- Two casts are not cosmetic, because `getJsonConfig()` puts values straight into the rendered JSON:
  a child's `entity_id` and a super attribute's `position` must both be **strings**.
