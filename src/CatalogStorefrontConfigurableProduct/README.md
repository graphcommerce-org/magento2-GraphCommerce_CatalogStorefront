# GraphCommerce_CatalogStorefrontConfigurableProduct

Configurable products in the documents.

- The variants feed writer: variant ids on the parent, parent ids on the variant.
- The option value details added to the exporter's options, the super attributes and their values stored compact on the document (`Model/Read/ConfigurableOptions` expands them), and the configurable price range over the variants' price index.

`etc/di.xml` keeps `parentId` in the minimal variant payload the exporter retains, so a removed relation still names both sides. A deletion row without that parent id is refused instead of leaving a stale relation in the documents; `bin/magento catalog-storefront:rebuild product` writes every retained variants row again.
