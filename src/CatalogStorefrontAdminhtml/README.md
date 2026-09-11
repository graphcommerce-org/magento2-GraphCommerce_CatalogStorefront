# Catalog Storefront Admin

**Catalog > Catalog Storefront** contains six native UI listings backed by stored
Views, Catalog Sources, Price Books, Stocks, Layers and Catalog Policies. Create
and Edit open native UI forms; Delete is confirmed and submitted through POST.
Read, create/edit and delete have separate ACL permissions.

Generic and platform-specific types share the same registry. Display names use
MageOS or Magento according to the installed base distribution. Native records
are imported once, then remain independently stored. No rows are recreated on
page reads. Stock Inventory Sources are distinct from Catalog Sources.

Source counters show latest accepted non-deleted product/category/attribute feed
records with separate pending and failed counts. Stock-feed counters belong to
Stocks. Unknown counts are not represented as zero. Pending does not establish an
active worker lease. Layers represent configured field ownership, not a list of
installed modules. Price Books can exist with no own prices.

Saving resource configuration does not publish it to live search or enable new
routing/protection policies. The form states this boundary. See the
[registry contract](../../docs/catalog-resource-registry.md) for relationships,
adapter types, validation and the local CRUD/render checks.
