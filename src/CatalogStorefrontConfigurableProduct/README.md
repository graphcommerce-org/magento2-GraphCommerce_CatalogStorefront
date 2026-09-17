# GraphCommerce_CatalogStorefrontConfigurableProduct

Configurable products in the documents. It depends on Magento_ConfigurableProduct,
Magento_ConfigurableProductDataExporter and Magento_ProductVariantDataExporter.

- The variants feed writer keeps the relation as id lists: `variantIds` on the parent,
  `parentIds` on the child.
- [`Model/DataExporter/Provider/ConfigurableOptions`](Model/DataExporter/Provider/ConfigurableOptions.php)
  stores the super attributes and their values compact on the document, and
  [`Model/Read/ConfigurableOptions`](Model/Read/ConfigurableOptions.php) expands them at
  read time. `OptionsV2WithoutConfigurable` keeps the raw configurable entries out of
  `optionsV2`, which took a third of a 13 KB configurable document.
- The configurable price range over the `priceIndex` entries of the variants.

[etc/di.xml](etc/di.xml) keeps `parentId` in the minimal variant payload the exporter
retains, so a deletion row names both sides of the relation. A deletion row without that
parent id is refused instead of leaving a stale relation;
`bin/magento catalog-storefront:rebuild product` writes every retained variants row again.
