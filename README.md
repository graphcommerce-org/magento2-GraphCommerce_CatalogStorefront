# GraphCommerce Catalog Storefront

A catalog read model for Magento 2 / Mage-OS, built on `magento/commerce-data-export`.

The feeds (products, prices, inventory, variants, reviews, categories, attributes)
are computed by the maintained exporter modules as ordinary Magento indexers. This
package implements the delivery seam (`ExportFeedInterface`) to assemble the feed
slices into one product document per store view in OpenSearch, and serves catalog
GraphQL reads from those documents. A request runs no SQL of its own; any miss falls
back to the core resolver.

Configured under Stores > Configuration > Catalog > Catalog > Catalog Storefront Document
Store: storefront indexing, serving GraphQL from documents, and search term recording.

## Modules

One composer package registers one module per directory under `src/`. The cut follows
core: a base module holds what any frontend can use, its `GraphQl` twin holds the GraphQL
resolver plugins and prefillers. A Hyvä or Luma integration adds `*Frontend` modules next
to the `*GraphQl` ones.

| Module | Serves |
| --- | --- |
| `GraphCommerce_CatalogStorefrontApi` | The contracts: document stores, feed writers, document fields, product documents, price ranges |
| `GraphCommerce_CatalogStorefrontGraphQlApi` | The GraphQL contracts: prefillers, hydration |
| `GraphCommerce_CatalogStorefrontOpenSearch` | The document stores on OpenSearch |
| `GraphCommerce_CatalogStorefront` | Feed delivery, documents to models, price ranges, metadata readers, plugins on non-GraphQL core |
| `GraphCommerce_CatalogStorefrontGraphQl` | Listings and layered navigation, categories, media, URL rewrites, custom attributes, linked products, prices |
| `GraphCommerce_CatalogStorefrontInventory` / `...InventoryGraphQl` | The stock slice / stock status, only_x_left_in_stock, quantity, min and max sale qty |
| `GraphCommerce_CatalogStorefrontConfigurableProduct` / `...ConfigurableProductGraphQl` | Variants and the configurable range / configurable options, variants, options selection |
| `GraphCommerce_CatalogStorefrontBundleProduct` / `...BundleProductGraphQl` | Bundle feed fields and the bundle range / bundle items, price details |
| `GraphCommerce_CatalogStorefrontGroupedProduct` / `...GroupedProductGraphQl` | The grouped range / grouped items |
| `GraphCommerce_CatalogStorefrontDownloadable` / `...DownloadableGraphQl` | The downloadable range / downloadable links and samples |
| `GraphCommerce_CatalogStorefrontReview` / `...ReviewGraphQl` | Review and rating feeds / reviews, rating summary and breakdown |

Each module depends only on the core modules it plugs into. Enable the ones the
shop's product types and features need, plus the two Api modules, OpenSearch, the base
and the GraphQl module.

## Folders

Inside a module the folders name the stage of the pipeline:

- `Model/DataExporter/` and `Plugin/DataExporter/`: patch-ups of commerce-data-export.
  `Provider/` classes add fields to the exporter's existing records through `et_schema.xml`,
  `Processor/` classes make a legacy feed export like the modern ones, the plugins fix
  what an exporter provider leaves out. They run at index time and may use SQL.
- `Model/Document/`: the document store side. `Delivery` receives each feed batch and
  hands it to the `Writer/` of that feed; `Field/` classes compute product document
  fields the feed lacks.
- `Model/Read/`: the request side any frontend shares: product documents to models,
  the price ranges, the metadata readers.
- In a GraphQl module: `Model/DocumentHydration` and `Model/Prefill/`, `Plugin/Resolver/`,
  `Plugin/DataProvider/`, `Plugin/Layer/`, `Plugin/Query/`.

## Extending

A module registers its parts through di.xml:

- `writers` on `Model\Document\Delivery`: a `FeedWriterInterface` per feed name.
- `fields` on the products writer: a `ProductDocumentFieldInterface` per computed field.
- `prefillers` on the GraphQl module's `Model\DocumentHydration`: a `PrefillerInterface` fills fields on
  the product value from the model and the document, so the executor returns them
  without a resolver call. List the fields under `prefilledFields` on `ReuseSchema`.
- `ranges` on `Model\Read\PriceRanges`: a `PriceRangeInterface` per product type id.
- `fieldDocumentKeys` and `baseFields` on the hydration tell a listing fetch which
  document keys a field needs.

Another search engine implements the two storage interfaces of the Api module and
sets the preferences in its own di.xml, as the OpenSearch module does.

## Development install

The package is not yet published, so a project links the module directories into
`app/code`. From the Magento root, with this repository cloned into `packages/`:

```sh
for d in packages/magento2-GraphCommerce_CatalogStorefront/src/*/; do
  ln -s ../../../$d app/code/GraphCommerce/$(basename $d)
done
bin/magento module:enable $(ls packages/magento2-GraphCommerce_CatalogStorefront/src | sed 's/^/GraphCommerce_/')
bin/magento setup:upgrade --keep-generated
bin/magento setup:di:compile
```

Then add the `catalog-store-front` connection block to `app/etc/env.php` (see
`src/CatalogStorefrontOpenSearch/Model/Client/Config.php` for the keys), run the
commerce-data-export indexers, and turn on Serve GraphQL From Documents. A composer install from the
package registers every module through its autoload instead of the links.

## Ideas and to do

1. **Cart, wishlist and order products.** A cart item's product still loads from the
   database. A plugin on the cart items data swaps in the document model by product
   id; the quote keeps what it owns, the row price, the options and the quantity checks.
2. **Luma frontend integration.** `*Frontend` modules next to the `*GraphQl` ones: the
   product listing collection, the product page and the layered navigation read from the
   base modules. The price rendering goes through the pricing system, so it needs its
   own document-backed price providers.
3. **Hyvä frontend integration.** The same base as Luma with Hyvä's view models.
4. **REST integration.** The product repository and the search API behind the same
   document models, for headless setups that read the catalog over REST.
5. **Split writer and reader deployments.** A minimal Mage-OS installation that only
   holds the read side, distributed close to the shoppers. Reads need no catalog tables,
   but a Magento bootstrap still needs a database and a cache for configuration, stores
   and EAV metadata, so this is a read replica plus a local cache per region, with the
   writers and the feeds in one place.
6. **The router at the edge.** The `route` query and the URL rewrite lookup still read
   the database. A URL rewrite read model next to the documents, with redirects and
   custom URLs, and products and categories queryable by url path, brings the whole
   router to the read side.
7. **Write conflicts under parallel feeds.** A retry on version conflict for the bulk
   updates, and a re-read for the writers that merge into a stored document (prices,
   variants, composite links), so parallel feed threads cannot lose an update.
8. **Category attributes on the category documents.** Custom category attributes such
   as SEO fields fall back to the database; a category attributes slice like the
   product one serves them.
9. **More price display setups.** Prices are served only with the base currency, prices
    excluding tax and no fixed product taxes. Currency conversion and tax-inclusive
    display can be computed at read time from the same rows.
10. **Strict mode for test environments.** A setting that reports a fallback to the
    database instead of taking it silently, so a missing document or an unserved field
    shows up outside the parity harness.
11. **Search term analytics as its own concern.** Recording is off by default; when
    search analytics comes back it belongs off the request path, in a queue or the
    search engine's own logs.
12. **Package publishing.** One `composer.json` per module directory and a subtree split, so the
    modules install separately while the repository stays one.

## Parity

`dev/parity/run.php <endpoint>` runs every query in `dev/parity/queries/` against the
database path and the document path, diffs the responses and, with the attribution
module (`dev/attribution/Module`, linked as `GraphCommerce_CatalogStorefrontAttribution`)
enabled in the worker, fails a document-path query that runs a SQL lookup. See
`CLAUDE.md` for the operating notes and the known deviations.
