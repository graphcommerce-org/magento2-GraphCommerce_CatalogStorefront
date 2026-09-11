# Catalog Storefront Admin

**Catalog > Catalog Storefront** contains six native UI listings: Views, Catalog
Sources, Price Books, Stocks, Layers and Catalog Policies. Display names use
MageOS or Magento according to the installed base distribution.

In Open Source these sections present existing native configuration read-only.
The shared forms show the configuration available through Catalog Cloud. The
Open Source provider cannot create, edit or delete independent resources; that
implementation is supplied separately, not enabled by a UI flag.

Source counters show accepted product/category/attribute feed records with
separate pending and failed counts. Stock counters belong to Stocks. Unknown
counts are not represented as zero. Inventory Sources are distinct from Catalog
Sources.

The native UI uses service interfaces and retains Admin authentication, form keys
and ACL checks. See [catalog resource administration](../../docs/catalog-resource-registry.md)
for the package boundary and local rendering checks.
