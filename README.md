# GraphCommerce Catalog Storefront

A catalog read model for Magento 2 / Mage-OS, built on `magento/commerce-data-export`.

The feeds (products, prices, inventory, variants, reviews, categories, attributes)
are computed by the maintained exporter modules as ordinary Magento indexers. This
package implements the delivery seam (`ExportFeedInterface`) to assemble the feed
slices into one product document per store view in OpenSearch, and serves catalog
GraphQL reads from those documents. A request runs no SQL of its own for catalog
data; any miss falls back to the core resolver.

## Requirements

- Mage-OS or Magento Open Source 2.4.7 or later, with OpenSearch as the configured
  search engine. The document store uses the same connection (Stores > Configuration
  > Catalog > Catalog Search) through core's OpenSearch client. Elasticsearch is not
  supported.
- `magento/commerce-data-export` 103.4 or later. Adobe publishes it on
  repo.magento.com. Mage-OS keeps a fork at
  [mage-os/mageos-commerce-data-export](https://github.com/mage-os/mageos-commerce-data-export)
  (and a mirror, `mage-os/mirror-commerce-data-export`), both updated on 3 September
  2026; its modules are not on repo.mage-os.org yet, so an install without Adobe keys
  clones the fork and adds its module directories as composer `path` repositories,
  as the CI workflow does.

## Settings

Stores > Configuration > Catalog > Catalog > Catalog Storefront Document Store:

| Setting | Effect |
| --- | --- |
| Enable Storefront Indexing | The feeds are written into the document store as they export. |
| Serve GraphQL From Documents | Per store view: catalog GraphQL reads come from documents. |
| Allow Request Override | A request picks its own path with the `X-Catalog-Storefront` header: `documents` or `core`. For test environments, the admin API explorer and the parity gate. The path is a factor of the response cache id and of the resolver result cache keys. |
| Strict Mode | A request reports its path, every fallback to core with its reason and every SQL statement under `extensions.catalogStorefront` of the GraphQL response. Test environments only: the report exposes statements. |
| Record Search Terms | Off by default: the search terms report and the suggestions stop updating, the request path stops writing. |
| Index Prefix | The indices are named prefix, entity and store view code, for example `catalog_storefront_product_default`. |

Stores > Configuration > Catalog > Catalog > Layered Navigation > Price Navigation Step
Calculation gets a fourth option, Single range: the `price` aggregation is one option from
the lowest to the highest price of the result, the bounds a price slider reads. Core's
modes split it into intervals with two or three more search queries per listing.

## Modules

The root package registers one module per directory under `src/`, and each directory is a
composer package of its own (`graphcommerce/module-catalog-storefront-<name>`) with the core
modules it needs, so the GraphQL, MSI and product type requirements sit in the modules that
use them and a subtree split publishes them one by one; the root package replaces them all.
The cut follows core: a base module holds what any frontend can use, its `GraphQl` twin holds
the GraphQL resolver plugins and prefillers. A Hyvä or Luma integration adds `*Frontend`
modules next to the `*GraphQl` ones.

| Module | Serves |
| --- | --- |
| `GraphCommerce_CatalogStorefrontApi` | The contracts: document stores, feed writers, document fields, product documents, price ranges |
| `GraphCommerce_CatalogStorefrontGraphQlApi` | The GraphQL contracts: prefillers, hydration |
| `GraphCommerce_CatalogStorefrontOpenSearch` | The document stores on OpenSearch, through core's client |
| `GraphCommerce_CatalogStorefront` | Feed delivery, documents to models, metadata readers, strict mode, plugins on non-GraphQL core |
| `GraphCommerce_CatalogStorefrontGraphQl` | Listings and layered navigation, categories, media, URL rewrites, custom attributes, linked products, the request path, the parity command |
| `GraphCommerce_CatalogStorefrontPrice` / `...PriceGraphQl` | Display prices and price ranges: currency and tax at read time through core's tax service / the prices prefiller, the customer's tax address |
| `GraphCommerce_CatalogStorefrontWorker` | What a persistent PHP worker keeps between requests: kept schemas, validated documents, the guest tax, customer group and currency rate memos, each lifted by a cache generation. Nothing is keyed by customer. Only for FrankenPHP worker mode. |
| `GraphCommerce_CatalogStorefrontExplorer` | The path switcher in the MageOS_GraphQLAdminHtml API explorer |
| `GraphCommerce_CatalogStorefrontProfiler` | Times the document store client in a MageOS_Profiler trace, request bodies included |
| `GraphCommerce_CatalogStorefrontInventory` / `...InventoryGraphQl` | The stock slice / stock status, only_x_left_in_stock, quantity, min and max sale qty |
| `GraphCommerce_CatalogStorefrontConfigurableProduct` / `...ConfigurableProductGraphQl` | Variants and the configurable range / configurable options, variants, options selection |
| `GraphCommerce_CatalogStorefrontBundleProduct` / `...BundleProductGraphQl` | Bundle feed fields and the bundle range / bundle items, price details |
| `GraphCommerce_CatalogStorefrontGroupedProduct` / `...GroupedProductGraphQl` | The grouped range / grouped items |
| `GraphCommerce_CatalogStorefrontDownloadable` / `...DownloadableGraphQl` | The downloadable range / downloadable links and samples |
| `GraphCommerce_CatalogStorefrontReview` / `...ReviewGraphQl` | Review and rating feeds / reviews, rating summary and breakdown |

Each module depends only on the core modules it plugs into. Enable the ones the
shop's product types and features need, plus the two Api modules, OpenSearch, the two Price modules, the base
and the GraphQl module. The Worker module belongs on a FrankenPHP worker deployment
only; under php-fpm it costs a cache read per request and keeps nothing.

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
  the price ranges, the display prices, the metadata readers.
- In a GraphQl module: `Model/DocumentHydration` and `Model/Prefill/`, `Plugin/Resolver/`,
  `Plugin/DataProvider/`, `Plugin/Layer/`, `Plugin/Query/`.

## Prices

The price feed rows are stored per customer group id, next to a nested price index
with one entry per customer group (regular and final price, base currency, before tax)
that the composite price aggregations run over: the mapping holds three fields
whatever the number of customer groups. The display currency and the taxes are applied
at request time the way core's price classes and tax adjustment apply them, through
core's own tax service with the product's tax class (a dynamic bundle's selections
with their own); the Worker module keeps its rate lookups between requests.
Fixed product taxes are not answered. A customer group created after a price row was
exported gets its index entry when the row exports again: truncate the prices feed
table and reindex it.

## Extending

A module registers its parts through di.xml:

- `writers` on `Model\Document\Delivery`: a `FeedWriterInterface` per feed name.
- `fields` on the products writer: a `ProductDocumentFieldInterface` per computed field.
- `prefillers` on the GraphQl module's `Model\DocumentHydration`: a `PrefillerInterface` fills fields on
  the product value from the model and the document, so the executor returns them
  without a resolver call. List the fields under `prefilledFields` on
  `Plugin\Query\RoutePrefilledFields`.
- `ranges` on `Model\Read\PriceRanges`: a `PriceRangeInterface` per product type id.
- `fieldDocumentKeys` and `baseFields` on the hydration tell a listing fetch which
  document keys a field needs.
- `mappings` on `EntityMappings`: the fields of an entity that filter, sort or aggregate.

Another search engine implements the two storage interfaces of the Api module and
sets the preferences in its own di.xml, as the OpenSearch module does.

`docs/skills/catalog-storefront-compatibility/SKILL.md` is the guide for a module that
adds catalog data: what to build at index time and at request time, and how to prove
it with the parity gate. It is written to be loaded as a skill by an LLM.

## Parity gate

```sh
bin/magento catalog-storefront:parity https://shop.example/graphql
```

runs every query in `dev/parity/queries/` (or `--queries=<dir>`) against the core path
and the document path, picked per request with the `X-Catalog-Storefront` header, and
diffs the responses. It needs Allow Request Override on; with Strict Mode on it also
fails a document-path query that ran a SQL lookup and prints its fallbacks and writes.
`--dump=<dir>` keeps both responses of every query. A query file sends its own request
headers through `# @header Content-Currency: EUR` comment lines. See `CLAUDE.md` for the
operating notes, the fixtures the query set needs and the known deviations.

## Rebuild

```sh
bin/magento catalog-storefront:rebuild [product|category|attribute|review|rating ...]
```

drops the indices of the given entities (all by default) in every store view, truncates
the feed tables of the feeds that write them, so the exporter re-exports every row instead
of skipping the unchanged ones, and runs those feed indexers. Needed after a mapping change,
after a new customer group, and whenever the documents drifted from the database. The read
path falls back to core while the documents are away. A module that writes a feed registers
it under `feeds` on the command.

## Tests

`phpunit.xml.dist` runs the unit tests of every module against the Magento
installation that holds the package (`MAGENTO_ROOT`, else the project two levels up).
`.github/workflows/ci.yml` runs them on the latest Mage-OS release through the
[graycore actions](https://github.com/graycoreio/github-actions-magento2), then installs
the sample data with OpenSearch, MySQL and Redis as service containers, adds the query set's
fixtures, exports the feeds and runs the parity gate in three price setups: excluding tax,
catalog prices including tax, both prices displayed. PHPUnit runs from its phar in both
jobs: Magento's composer.json excludes every `Test` directory from the classmap, which
drops PHPUnit's own event classes.

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

Then run the commerce-data-export indexers and turn on Serve GraphQL From Documents. A
composer install from the package registers every module through its autoload instead
of the links.

## Ideas and to do

1. **Cart, wishlist and order products.** A cart item's product still loads from the
   database. A plugin on the cart items data swaps in the document model by product
   id; the quote keeps what it owns, the row price, the options and the quantity checks.
2. **Luma frontend integration.** `*Frontend` modules next to the `*GraphQl` ones: the
   product listing collection, the product page and the layered navigation read from the
   base modules. The price rendering goes through the pricing system, so it needs its
   own document-backed price providers.
3. **Hyvä frontend integration.** The same base as Luma with Hyvä's view models.
4. **Parity on every surface.** The gate compares GraphQL responses. A Luma or Hyvä
   listing needs a gate of its own: the rendered listing and product page on both
   paths, so an integrator proves an extension on every surface it touches.
5. **REST integration.** The product repository and the search API behind the same
   document models, for headless setups that read the catalog over REST.
6. **Split writer and reader deployments.** A minimal Mage-OS installation that only
   holds the read side, distributed close to the shoppers. Reads need no catalog tables,
   but a Magento bootstrap still needs a database and a cache for configuration, stores
   and EAV metadata, so this is a read replica plus a local cache per region, with the
   writers and the feeds in one place.
7. **The router at the edge.** The `route` query and the URL rewrite lookup still read
   the database. A URL rewrite read model next to the documents, with redirects and
   custom URLs, and products and categories queryable by url path, brings the whole
   router to the read side.
8. **Write conflicts under parallel feeds.** A retry on version conflict for the bulk
   updates, and a re-read for the writers that merge into a stored document (prices,
   variants, composite links), so parallel feed threads cannot lose an update.
9. **A blue/green rebuild.** The rebuild command drops an entity's documents first,
   so the read path serves from core until the export completes. A rebuild into a
   fresh index behind an alias, switched over when it is complete, keeps the
   documents live.
10. **Category attributes on the category documents.** Custom category attributes such
    as SEO fields fall back to the database; a category attributes slice like the
    product one serves them.
11. **Fixed product taxes.** The last price display setup that falls back to core.
    The weee amounts per product travel on the document; the read side adds them the
    way the weee adjustment does.
12. **Composite ranges per child tax class.** A configurable or grouped range is taxed
    with the parent's tax class; core taxes each child's regular price with the child's
    own class. A terms aggregation on the child's tax class next to the nested price
    index makes the regular range exact. Bundles are exact already.
13. **Search term analytics as its own concern.** Recording is off by default; when
    search analytics comes back it belongs off the request path, in a queue or the
    search engine's own logs.
14. **Package publishing.** A subtree split of the module directories to their own
    repositories and a release on packagist, so the modules install separately while
    the repository stays one.
