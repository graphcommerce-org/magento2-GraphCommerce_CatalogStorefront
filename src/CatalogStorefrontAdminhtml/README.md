# GraphCommerce_CatalogStorefrontAdminhtml

Catalog > Catalog Storefront: one Admin page that shows what the catalog read model runs
on, read-only. It depends on Magento_Backend and Magento_Ui and holds its own ACL resource
`GraphCommerce_CatalogStorefrontAdminhtml::views`.

The page holds six native UI listings, each built from the Magento configuration:

| Listing | Rows |
| --- | --- |
| Catalog Views, Catalog Sources | One per active store view, with its locale, its stock, its price books and its policies |
| Price Books | A root book per base currency, each website under its root and one customer group book under every website |
| Stocks | The MSI stocks, where GraphCommerce_CatalogStorefrontInventory is enabled |
| Catalog Layers | Empty |
| Catalog Policies | The in-stock-only rule of the store configuration, where it is set |

[`Model/SourceFeedCounts`](Model/SourceFeedCounts.php) counts the accepted product,
category and attribute feed records per store view, with the pending and failed counts next
to them. A count the feed tables do not answer stays empty. Inventory Sources and Catalog
Sources are two different things.

The controllers keep the Admin authentication, the form key and the ACL check. A save and a
delete of a row raise, because the base module binds the resource repository to a read-only
view of the Magento configuration
([`Model/Registry/ReadOnlyConfiguration`](../CatalogStorefront/Model/Registry/ReadOnlyConfiguration.php)).
Another package binds its own implementation of
[`Service\ConfigurationInterface`](../CatalogStorefrontApi/Service/ConfigurationInterface.php)
to manage resources of its own.

[dev/admin-views/README.md](../../dev/admin-views/README.md) holds the smoke check that
renders these listings without an HTTP request.
