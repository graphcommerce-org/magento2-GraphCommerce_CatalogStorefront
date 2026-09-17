# Catalog resource administration

Catalog > Catalog Storefront shows six catalog resource listings with the Magento Admin UI
components: Catalog Views, Catalog Sources, Price Books, Stocks, Catalog Layers and Catalog
Policies. The labels follow the installed distribution.

The base module binds `Service\ConfigurationInterface` to a read-only view of the Magento
store views, websites, customer groups and stocks, so the listings state the configuration
the store already has and a save raises. A package that owns catalog resources binds its own
implementation and the same forms then manage them. The controllers keep the Admin
authentication, the form key and the ACL check.

Source counts show the imported product, category and attribute records with their pending
and failed counts. Stock counts sit under Stocks. A count the feed tables do not answer stays
empty. Inventory Sources and Catalog Sources are two different things.

## Local UI verification

```sh
MAGENTO_ROOT="$PWD" php vendor/graphcommerce/magento-catalog-storefront/dev/registry/render.php overview
MAGENTO_ROOT="$PWD" php vendor/graphcommerce/magento-catalog-storefront/dev/registry/render.php views
```

These commands render the real Admin components. An authenticated browser stays the visual
and the authorization check.
