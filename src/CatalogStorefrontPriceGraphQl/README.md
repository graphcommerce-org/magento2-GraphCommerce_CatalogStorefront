# GraphCommerce_CatalogStorefrontPriceGraphQl

The price slice of the catalog GraphQL read path.

- The prices prefiller: price_range, price, price_tiers and tier_prices on the product value.
- The customer's tax address as one join read of three columns per request, where core loads the customer for every taxed amount.
