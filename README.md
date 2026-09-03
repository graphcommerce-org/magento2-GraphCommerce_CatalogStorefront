# GraphCommerce_CatalogStorefront

A catalog read model for Magento 2 / Mage-OS, built on `magento/commerce-data-export`.

The feeds (products, prices, variants, inventory, url rewrites, scopes) are computed
by Adobe's maintained exporter modules as ordinary Magento indexers. This module
implements the delivery seam (`ExportFeedInterface`) to store the feed documents
locally and serves catalog GraphQL reads from them.

Under construction. History: an earlier iteration served reads from
`HydratorPool::extract()` documents; it was replaced by the feed-based design.
