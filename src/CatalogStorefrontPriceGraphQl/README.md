# GraphCommerce_CatalogStorefrontPriceGraphQl

The price fields of the catalog GraphQL read path. It plugs into Magento_CatalogGraphQl and
Magento_WeeeGraphQl.

- [`Model/Prefill/Prices`](Model/Prefill/Prices.php) fills `price_range`, `price`,
  `price_tiers` and `tier_prices` on the product value. It hands a composite product to
  core while the store prices fixed product taxes, because core prices a composite by the
  amounts of its children.
- [`Plugin/Resolver/FixedProductTaxFromValue`](Plugin/Resolver/FixedProductTaxFromValue.php)
  answers `fixed_product_taxes` from the value the prefiller filled.
- [`Plugin/Tax/CustomerAddressColumns`](Plugin/Tax/CustomerAddressColumns.php) reads the
  three tax columns of a signed-in customer's default address in one join and holds them
  for the request, because every taxed amount builds its own rate request.

A product type module whose prefiller rewrites a price field sequences after this module,
so the prices prefiller runs first.
