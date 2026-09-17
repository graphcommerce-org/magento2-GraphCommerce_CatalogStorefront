# GraphCommerce_CatalogStorefrontPrice

How a price on a document becomes the amount a request displays. It depends on
Magento_Tax and Magento_Weee.

- [`Model/Read/DisplayPrice`](Model/Read/DisplayPrice.php) takes the base currency price
  before tax that the documents hold, converts it the way core's price classes convert and
  taxes it through core's tax service with the product's tax class, for the customer group
  and the destination of the request. Display including, excluding or both, catalog prices
  including tax, cross-border trade and the tax classes follow the core configuration.
- [`Model/Read/FixedProductTax`](Model/Read/FixedProductTax.php) picks the `fixedProductTaxes`
  rows of the request's destination from the document and taxes them as core's weee model
  does. [`Model/DataExporter/Provider/FixedProductTaxes`](Model/DataExporter/Provider/FixedProductTaxes.php)
  puts those rows on the products feed.
- [`Model/Read/PriceRanges`](Model/Read/PriceRanges.php) answers the range of a product by
  its type id. A product type module adds its own range under `ranges`; this module holds
  the range of a simple and a virtual product.
