# GraphCommerce_CatalogStorefrontConfigurableProduct

Configurable products in the documents.

- The variants feed writer: variant ids on the parent, parent ids on the variant.
- The option value details added to the exporter's options, the super attributes and their values stored compact on the document (`Model/Read/ConfigurableOptions` expands them), and the configurable price range over the variants' price index.

The exporter retains `parentId` in its minimal variant payload so a relation deletion still identifies both sides after the live provider stops returning it. After upgrading an installation whose retained rows predate this field, run `bin/magento catalog-storefront:rebuild product` before accepting incremental catalog writes. That rebuild starts a fresh document generation and recreates every retained variants row. A plain variants indexer reindex is insufficient: unchanged feed hashes can skip delivery and it does not replace the document generation. A historical deletion without the retained parent id is refused and retried instead of leaving a stale relation silently.
