# GraphCommerce_CatalogStorefrontGraphQl

Catalog GraphQL served from the documents. It plugs into Magento_CatalogGraphQl,
Magento_UrlRewriteGraphQl, Magento_RelatedProductGraphQl, Magento_EavGraphQl,
Magento_GraphQlCache, Magento_CustomerGraphQl and Magento_Integration.

- `Plugin/DataProvider/`: the product listing and the product lookup are rebuilt from
  documents. One page is one multi-search: the documents by id, and the composite price
  data when the query selects a price.
- [`Model/DocumentHydration`](Model/DocumentHydration.php) runs the prefillers in di.xml
  order, and [`Plugin/Query/RoutePrefilledFields`](Plugin/Query/RoutePrefilledFields.php)
  routes a prefilled field to the filled value, or to the core resolver where none is
  filled.
- `Plugin/Resolver/`: categories, media gallery, URL rewrites, custom attributes, the
  attributes list and the linked products.
  [`Model/CategoryDocuments`](Model/CategoryDocuments.php) answers the `categories` and
  `categoryList` queries from the category documents.
- `Plugin/Layer/`: one request primes the attribute and category documents an aggregation
  needs before core's layer builders run, so the builders ask no question of their own.
- `Plugin/Request/` and `Plugin/Token/`: a customer JWT carries the customer group and the
  website, and the request path reads the token once. A signed-in catalog request then
  reads the revoked token table, the address join and the rates of the product tax classes.
- [`Model/Query/PageSizeLimit`](Model/Query/PageSizeLimit.php) refuses a page over 2 000
  items on both paths.
- `bin/magento catalog-storefront:parity <endpoint>`: every query of
  [dev/parity/queries](../../dev/parity/queries) on both paths, diffed. `--soak` repeats
  the accepted queries with the context varied per request.

[etc/di.xml](etc/di.xml) sets a preference on Magento's `AttributeOptionProvider`, on both
paths. Core's query holds no EAV entity type predicate and groups by attribute code, so a
customer attribute of the same name leaks its option into a product facet, and equal
merchant sort positions stay unordered. The replacement restricts the query to product
attributes and orders the ties by numeric option id. Plugins declared for Magento's
provider keep their place in the inherited interceptor chain; a second preference for that
provider conflicts by the normal DI merge rules.

A module adds a prefiller under `prefillers` on `DocumentHydration`, its fields under
`prefilledFields` on `RoutePrefilledFields`, and a parity verdict under `judges` on the
command.
