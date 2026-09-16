# GraphCommerce_CatalogStorefrontConfigurableProduct

Configurable products in the documents.

- `Model/DataExporter/Provider/Variants` lists a configurable's children by sku on the parent's products feed row, where the exporter's own provider answers an empty list. With `parents` on the child row it gives `CatalogStorefront/Model/Document/CompositeLinks` both sides of the relation, which it keeps by id as `variantIds` on the parent and `parentIds` on the child.
- `etc/mview.xml` subscribes the products feed to `catalog_product_super_link` by `parent_id`, so a link change re-exports the parent; the child follows the exporter's own `catalog_product_relation` subscription.
- The option value details added to the exporter's options, the super attributes and their values stored compact on the document (`Model/Read/ConfigurableOptions` expands them), and the configurable price range over the variants' price index.
