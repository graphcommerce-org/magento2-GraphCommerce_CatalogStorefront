# GraphCommerce_CatalogStorefrontAdminhtml

The read-only **Catalog > Catalog Storefront Views** page uses five native Magento UI listings. Views are compatibility contexts derived from active store views; no independent view registry exists yet. Sources have the specialist type **Magento Store View Catalog**.

Source counts show latest accepted, non-deleted records in the product/category/attribute feeds for each store-view code. Price/stock rows do not inflate product counts. Pending and failed deliveries have separate badges, including pending/failed deletes. Pending does not mean a worker is actively processing the item. A failed newer row may coexist with an older document still stored; these are import receipt counts, not a scan of retained documents. Unindexed source changes have no feed row and are not counted. Missing/unreadable feeds show unavailable; empty feeds show zero. Grouped SQL is read only on the sources listing and shared within the request.

Layers is empty until independent layers exist. Installed content, inventory and review modules are not layer records. Policies shows **in-stock-only** for derived views where Magento **Show Out of Stock Products** is disabled. This reflects source configuration; the live search publication captures that setting and must be refreshed after a change. Managed policy editing is not implemented.

Premium price books retain their canonical identity/hierarchy and use readable display codes: **magento_root**, the website code, and **website-group-ID**. A descriptor can legitimately own no prices.

Actions without an implemented editor explain availability in native Magento modals and do not save anything. No independent view, layer or policy CRUD is implied.

See [`dev/admin-views`](../../dev/admin-views/README.md) for the native render check and the rollback-only source delivery counter verification.
