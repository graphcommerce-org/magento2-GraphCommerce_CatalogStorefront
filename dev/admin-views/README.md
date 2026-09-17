# Admin Views smoke

`smoke.php` boots the Admin area of a store-backed installation and renders the six
listings of Catalog > Catalog Storefront with the Admin theme, without an HTTP request and
without an Admin user. It checks the merged route and ACL, the GET-only controller and the
merged layout declaration, and writes a JSON report of hashes and public identifiers.

```sh
CATALOG_VIEWS_SMOKE=read-only \
MAGENTO_ROOT=/var/www/html \
php dev/admin-views/smoke.php
```

`CATALOG_VIEWS_PACKAGE_REFERENCE` requires an exact composer source commit; the report
records the installed reference that composer gives.

The smoke saves no configuration, runs no indexer, reads no catalog document and creates no
Admin user. Booting the Admin area fills the normal configuration and layout caches.

`online.sh` streams `smoke.php` into a running writer container with `kubectl` and `jq`. It
binds the result to a namespace, a pod UID, a pulled image id and a package commit, and
checks the same pod identity before and after the run:

```sh
CATALOG_VIEWS_NAMESPACE=<namespace> \
CATALOG_VIEWS_POD=<pod> \
CATALOG_VIEWS_EXPECTED_POD_UID=<uid> \
CATALOG_VIEWS_EXPECTED_IMAGE_ID=<imageID> \
CATALOG_VIEWS_PACKAGE_REFERENCE=<commit> \
dev/admin-views/online.sh > catalog-views-admin.json
```

Resolve the pod UID and `status.containerStatuses[].imageID` after the intended release is
Ready. A replaced pod fails the report.

Two probes check the counts against a local fixture and roll every write back:

```sh
MAGENTO_ROOT=/path/to/magento php dev/admin-views/verify-source-feed-counts.php default
MAGENTO_ROOT=/path/to/magento php dev/admin-views/verify-stock-feed-counts.php 1
```

An authenticated browser stays the visual and authorization check. Open Catalog > Catalog
Storefront with an existing session and compare the page with the report:

1. The page holds Catalog Views, Catalog Sources, Price Books, Stocks, Catalog Layers and
   Catalog Policies, in that order.
2. Views and Sources hold one row per active store view, with the locale and the scope
   identifiers of the Magento configuration.
3. Price Books holds a root book per base currency, each website under its root and one
   customer group book under every website.
4. Catalog Layers is empty.
5. Catalog Policies shows the in-stock-only rule where the store configuration sets it.
6. The management actions are disabled and save nothing.
7. The six listings keep their compact table hierarchy at narrow widths.

A screenshot holds the page content alone, without the address bar and other
session-bearing browser interface.
