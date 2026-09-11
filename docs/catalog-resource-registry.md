# Catalog resource administration

The Open Source package presents six catalog resource sections using native
MageOS/Magento Admin UI components: Views, Catalog Sources, Price Books, Stocks,
Layers and Catalog Policies. Platform labels follow the installed distribution.

Its provider presents the existing native catalog configuration read-only.
Independent resource management requires Catalog Cloud. The package does not
contain generic resource tables, persistence, View assembly, book resolution,
layer processing or cloud publication implementations. Changing a UI capability
flag cannot enable an absent implementation.

The shared Admin forms describe the available configuration. Service interfaces
connect the UI to a separately supplied provider. Controllers retain native Admin
authentication, form keys and ACL checks; the provider owns resource operations.

Source counts show imported product, category and attribute records with pending
and failed counts where available. Stock counts appear under Stocks. Unknown
counts are not shown as zero. Inventory Sources and Catalog Sources are distinct.

## Local UI verification

```sh
MAGENTO_ROOT="$PWD" php vendor/graphcommerce/magento-catalog-storefront/dev/registry/render.php overview
MAGENTO_ROOT="$PWD" php vendor/graphcommerce/magento-catalog-storefront/dev/registry/render.php views
```

These checks render real Admin components. Authenticated browser interaction and
visual verification remain separate checks. Implementation design, persistence,
publication and service acceptance evidence belong in the private package.
