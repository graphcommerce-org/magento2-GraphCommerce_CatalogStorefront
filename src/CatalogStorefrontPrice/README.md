# GraphCommerce_CatalogStorefrontPrice

How a document price becomes the amount a request displays.

- `DisplayPrice`: a base currency price before tax, as the documents hold it, converted to the display currency and taxed through core's tax service for the request's customer group and destination. Display incl, excl or both, catalog prices incl or excl tax, cross-border trade and the tax classes follow core config. Fixed product taxes from the document's rows, picked for the request's destination and taxed as core's weee model taxes them (`FixedProductTax`).
- `PriceRanges`: the price range of a product by type id; a type module adds its own range.
