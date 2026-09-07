# GraphCommerce Catalog Storefront

A catalog read model for Magento 2 / Mage-OS, built on `magento/commerce-data-export`.

The feeds (products, prices, inventory, variants, reviews, categories, attributes)
are computed by the maintained exporter modules as ordinary Magento indexers. This
package implements the delivery seam (`ExportFeedInterface`) to assemble the feed
slices into one product document per store view in OpenSearch, and serves catalog
GraphQL reads from those documents; any miss falls back to the core resolver.

## Scope

This package runs in the monolith, next to the catalog database. The read path serves
from documents and may read the database where a document cannot answer, or where a
lookup is cheaper than carrying the data on the document; the fallback to the core
resolver is the norm. The feeds are a latency layer over the database, not a
replacement. A request with the storefront key gets every fallback to core reported, and
a MageOS_Profiler trace shows the statements a request runs, so the cost of a lookup
stays visible and a lookup can become a document field later. Extensions plug in
through the Api modules, never through a preference on a class of this package.

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
- A fulltext reindex after the install. The Search module writes the product id into the
  fulltext document as an integer field and sorts every listing's tie-break on it, where core
  runs a script over every matching document; the install invalidates the fulltext indexer.

## Settings

Stores > Configuration > Catalog > Catalog > Catalog Storefront Document Store:

| Setting | Effect |
| --- | --- |
| Enable Storefront Indexing | The feeds are written into the document store as they export. |
| Serve GraphQL From Documents | Per store view: catalog GraphQL reads come from documents. |
| Storefront Key | Generated when the page is saved with the field empty. A request that sends it in the `X-Catalog-Storefront-Key` header picks its own path with the `X-Catalog-Storefront` header (`documents` or `core`) and gets its path and every fallback to core with its reason under `extensions.catalogStorefront` of the response. The admin API explorer and the parity gate send it. The path is a factor of the response cache id and of the resolver result cache keys. |
| Index Prefix | The indices are named prefix, entity and store view code, for example `catalog_storefront_product_default`. |

Stores > Configuration > Catalog > Catalog > Catalog Storefront Search, from the
`GraphCommerce_CatalogStorefrontSearch` module, which also runs without the document store:

| Setting | Effect |
| --- | --- |
| Sort Ties On The Product Id Field | On by default. Every listing sorts equal products by product id; core computes the id with a script for every matching product (41 ms of an unfiltered listing over 308 000 products), the module reads it from a field it writes into the fulltext document (6 ms). Needs a fulltext reindex after the install, which the install schedules. |
| Record Search Terms | Off by default: the search terms report and the suggestions stop updating, the request path stops writing. |

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
| `GraphCommerce_CatalogStorefront` | Feed delivery with the cache purge after a write, documents to models, metadata readers, the fallback report, the storefront key, the rebuild and status commands, plugins on non-GraphQL core |
| `GraphCommerce_CatalogStorefrontGraphQl` | Listings and layered navigation, categories, media, URL rewrites, custom attributes, linked products, the request path, the parity command |
| `GraphCommerce_CatalogStorefrontPrice` / `...PriceGraphQl` | Display prices and price ranges: currency and tax at read time through core's tax service / the prices prefiller, the customer's tax address |
| `GraphCommerce_CatalogStorefrontWorker` | What a persistent PHP worker keeps between requests: kept schemas, validated documents, the guest tax, customer group and currency rate memos, each lifted by a cache generation. Nothing is keyed by customer. Only for FrankenPHP worker mode. |
| `GraphCommerce_CatalogStorefrontExplorer` | The path switcher in the MageOS_GraphQLAdminHtml API explorer |
| `GraphCommerce_CatalogStorefrontProfiler` | Times the document store client in a MageOS_Profiler trace, request bodies included |
| `GraphCommerce_CatalogStorefrontSearch` | Cheaper core fulltext listings, each behind a setting: the entity id tie-break on a field instead of a script, one field name lookup per attribute per request, optional search term recording. Stands alone, without the document store |
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
at request time the way core's price classes and tax adjustment apply them, a final price
rounded to the decimals of its source (two for a special or tier price, four for a catalog
rule price), through
core's own tax service with the product's tax class (a dynamic bundle's selections
with their own); the Worker module keeps its rate lookups between requests.
Fixed product taxes are not answered. A new or deleted customer group changes the index
entries of every product, so its save truncates the prices feed table and invalidates the
feed indexer; the next cron run re-exports every row.

The price slice grows with the customer groups, the document writes do not: a product's
rows of every group arrive in one feed batch, so a catalog rule change costs one document
write per product and store view whatever the number of groups. Measured on the demo
catalog with a rule on all groups, four groups against fifty-four: the price feed export
went from 4694 to 15794 rows in four seconds, a rule product's document from 6 KB to 23 KB,
the product index from 2 MB to 12 MB (one nested price entry per product and group), and the
200-item listing on the worker from 78 ms to 86 ms. Tens of groups are fine; thousands are
not, because the nested entries are per product, group and store view.

## Extending

A module registers its parts through di.xml:

- `writers` on `Model\Document\Delivery`: a `FeedWriterInterface` per feed name;
  `identities` next to it: per feed name, the cache tag and the row keys of the
  entities a batch touches, purged after the write.
- `feeds` on `Model\Feeds`: the feed metadata per entity and indexer id, for the
  rebuild and the status command.
- `fields` on the products writer: a `ProductDocumentFieldInterface` per computed field.
- `prefillers` on the GraphQl module's `Model\DocumentHydration`: a `PrefillerInterface` fills fields on
  the product value from the model and the document, so the executor returns them
  without a resolver call. List the fields under `prefilledFields` on
  `Plugin\Query\RoutePrefilledFields`.
- `ranges` on `Model\Read\PriceRanges`: a `PriceRangeInterface` per product type id.
- `mappings` on `EntityMappings`: the fields of an entity that filter, sort or aggregate.
- `judges` on the parity command: a `JudgeInterface` adds a verdict per query next to the
  response diff, with both responses in hand.

Another search engine implements the two storage interfaces of the Api module and
sets the preferences in its own di.xml, as the OpenSearch module does.

`docs/skills/catalog-storefront-compatibility/SKILL.md` is the guide for a module that
adds catalog data: what to build at index time and at request time, and how to prove
it with the parity gate. It is written to be loaded as a skill by an LLM.

## Measured

The GraphCommerce product list query for 200 items (`dev/parity/queries/13-*.graphql`
with `pageSize: 200`) on the demo catalog, over the wire on a development machine, median
of fifteen requests after three warm ones, guest, prices excluding tax:

| Runtime | Core path | Document path |
| --- | --- | --- |
| php-fpm (PHP 8.4, opcache on) | 816 ms | 112 ms |
| FrankenPHP worker (Worker module on) | 425 ms | 78 ms |

The document path carries most of the gain on php-fpm already; the worker adds the kept
schema and the memos. Both runtimes read the same database and OpenSearch.

## Measured at scale

The large performance profile of `setup:performance:generate-fixtures` without its orders
(`setup/performance-toolkit/profiles/ce/large-catalog.xml`), on a laptop with Docker
Desktop at 6 GB for MariaDB, OpenSearch and the worker together:

| | |
| --- | --- |
| Catalog | 300 000 simple and 8 000 configurable products with 192 000 variants, 502 050 products in all, every one in 5 websites; 3 000 categories; 4 customer groups; 20 catalog price rules |
| Generation | 25 minutes |
| Core indexers | stock 28 minutes (core's legacy stock indexer runs an attribute query per product under MSI), category products 2, price 2, EAV 1, fulltext about 5 per store view of 308 000 visible products |
| Products feed | 2.5 million rows in 64 minutes, 570 rows a second; the document store takes 0.41 ms a row, 15 of the 64 minutes; the rest is the exporter |
| Prices feed | 2.5 million rows in 9 minutes, 0.21 ms a row in the store |
| Stock feed | 494 000 rows in 7 minutes, 0.75 ms a row in the store |
| Variants feed | 194 000 rows in 4 minutes, 0.86 ms a row in the store |
| Rebuild | `catalog-storefront:rebuild product` into staged indices: 109 minutes (products feed 71, prices 15, variants 5, stock 18); `rebuild attribute` 5 seconds for 1 095 attributes per store view; a feed run over unchanged rows writes nothing |
| Documents | 502 050 product documents per store view, 1 to 3 GB per store view on disk, 12 GB in all; categories and attributes below 1 MB per store view |
| OpenSearch heap | 2 GB trips the parent circuit breaker while the fulltext indexer and the feeds write at once; 3 GB carries the load, next to the worker in a 10 GB Docker VM |
| Exporter feed tables | 24 GB for 1.8 million products rows with `PERSIST_EXPORTED_FEED` set, 174 bytes a row without it; the document store never reads them, so leave it unset |

Read side, the GraphCommerce product list query, median over the wire, guest, excluding
tax, no page cache, php-fpm on the host and the FrankenPHP worker in the Docker network next
to OpenSearch and MariaDB (a round trip from the host to a container costs 0.3 ms against
0.04 ms inside it, which core's hundreds of statements per listing pay and the document path
does not):

| Listing | fpm core | fpm documents | worker core | worker documents |
| --- | --- | --- | --- | --- |
| 24 items of a 12 463-product category | 238 ms | 93 ms | 133 ms | 41 ms |
| 200 items of that category | 1 433 ms | 145 ms | 624 ms | 98 ms |
| 200 items of a search over 308 000 visible products | 1 549 ms | 210 ms | 519 ms | 148 ms |
| 200 items unfiltered | 1 661 ms | 194 ms | 629 ms | 130 ms |
| 24 items unfiltered | 271 ms | 132 ms | 172 ms | 79 ms |

The unfiltered listings pay for core's facet aggregations over every visible product in the
search index, on both paths. A trace of the 24-item category listing on the document path,
php-fpm, 93 ms in all: 24 ms of bootstrap, 18 ms in which core builds the GraphQL schema
from its stitched config, 17 ms for core's search (5 ms in OpenSearch), 10 ms for the
document multi-search of the five requests, 3 ms for the review documents, 8 ms for the
aggregations (one multi-search primes the attribute documents of the option ids and the names
of the aggregated categories before core's layer builders run; the builders then ask no
question of their own), 4 ms after the response for core's cache id. The core path spends its time in price range
resolvers and hundreds of SQL statements. On the FrankenPHP worker the same request answers
in 41 ms: bootstrap and schema build are gone, a repeated query is parsed and validated once
per process (core's parser drops its cache between requests, 7 ms a request without the kept
documents), the search takes 7 ms, the multi-search 3 ms, the aggregations 5 ms; the worker then spends 33 ms resetting state before it takes the next
request (14 ms reloading the system config from Redis, gzip and unserialize included, 9 ms in
the worker module's reload, which reads the EAV attribute tables five times).

Core's search sorted every listing by score and then by a painless script that parses the
document id as a tie-break, which runs for every matching document: 41 ms of an unfiltered
listing over 308 000 visible products, 8 ms of a 12 000-product category. The fulltext
document now carries the product id as an integer field and the tie-break sorts on it: 6 ms
and 1 ms, the same order. What remains of the unfiltered search is its 30 aggregations,
28 ms, of which the category terms aggregation over an integer field takes 10; the same
aggregation over a keyword field takes 2, which core's category builder cannot take yet
because it compares the bucket keys strictly as integers.

Two reads used to load whole indices per request. The facet labels loaded every attribute
document of the store view (1.3 MB at 1 095 attributes, 40 ms on php-fpm) before the
attribute index got mappings for the option id, the filterable mode and the code; one query
now fetches the few attributes a facet needs. `custom_attributesV2` loaded the same set once
per request; a prefiller now fetches the codes the page's documents carry in one search (15 ms
for a 100-product page with hundreds of codes, the option labels being the payload). An
unfiltered listing's category facet labels the 500 categories of core's bucket from the
category documents: 4 ms on the worker with the name and the path read from doc values
instead of the parsed source; the search by id replaced a multi-get that cost three times as
much for hundreds of ids.
A page fetch takes its documents whole. Leaving the unselected keys out through a source
filter made OpenSearch parse every document: 15 ms of server time for 200 documents against
10 ms for the whole documents, and the smaller transfer did not pay it back. The documents
got smaller instead: a configurable carried its options twice, raw as `optionsV2` and shaped
as `configurableOptions`, and the raw entries were a third of its 13 KB; the field that
builds the shape drops them, and a configurable is 9 KB. The 200-item multi-search went from
18 to 12 ms on the worker for it. What remains is the document lookup itself, 5 ms, the price
aggregations, 5 ms, and the transfer and decode of 1.7 MB. The pure lookup would need the
listing slice stored as one field of its own to get lower.

Per row the document store is a quarter of the export; the exporter's own queries and
hashing are the rest. A full `indexer:reindex` of a feed re-sends every row whose hash or
status changed, and a core reindex that runs before the category and price indexes are
complete changes every products row, so the feeds run after the core indexers, in one call
each (`indexer:reindex <core ids>` then `indexer:reindex <feed ids>`), or the products feed
exports twice.

## Parity gate

```sh
bin/magento catalog-storefront:parity https://shop.example/graphql
```

runs every query in `dev/parity/queries/` (or `--queries=<dir>`) against the core path
and the document path, picked per request with the `X-Catalog-Storefront` header, and
diffs the responses. It sends the storefront key, so the configuration must have been
saved once, and prints every fallback of the document path. `--dump=<dir>` keeps both
responses of every query. A query file sends its own request
headers through `# @header Content-Currency: EUR` comment lines; `--header "Authorization:
Bearer <token>"` sends a customer token with every query, which makes it a signed-in gate,
and `--header "Store: second"` runs it on another store view. A query that fails is requested
again, up to `--attempts` times (three by default), before its verdict counts: a worker thread
that has not served the shape yet answers from a cold state once. See `CLAUDE.md` for the
operating notes, the fixtures the query set needs and the known deviations.

## Status

```sh
bin/magento catalog-storefront:status
```

prints per store view the documents of every entity, the product documents next to the
products assigned to the store's website, and per feed its rows, the rows the store did
not accept yet (the feed machinery retries them by cron), the last export and the state
of its indexer. It fails when rows are waiting or an indexer is invalid, so a deployment
check can call it.

## Rebuild

```sh
bin/magento catalog-storefront:rebuild [product|category|attribute|review|rating ...]
```

stages a fresh index per store view for the given entities (all by default), truncates the
feed tables of the feeds that write them, so the exporter re-exports every row instead of
skipping the unchanged ones, runs those feed indexers into the fresh indices, and then
moves the reads over and deletes the old indices. The reads keep the current documents
until the export is through; a rebuild that fails leaves them untouched and the next
rebuild replaces the staged index. Needed after a mapping change and whenever the
documents drifted from the database. Each entity and store view has one index behind two
aliases, `<prefix>_<entity>_<store view>` for the reads and its `_write` twin for the
writes. A module that writes a feed registers it under `feeds` on `Model\Feeds`.

## Tests

`phpunit.xml.dist` runs the unit tests of every module against the Magento
installation that holds the package (`MAGENTO_ROOT`, else the project two levels up).
`.github/workflows/ci.yml` runs them on the latest Mage-OS release through the
[graycore actions](https://github.com/graycoreio/github-actions-magento2), then installs
the sample data with OpenSearch, MySQL and Redis as service containers, adds the query set's
fixtures, exports the feeds and runs the parity gate in three price setups: excluding tax,
catalog prices including tax, both prices displayed; each as a guest, as a signed-in
customer with a Michigan address, and on the store view of a second website that sells
every product. PHPUnit runs from its phar in both
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

1. **Integration tests.** Magento integration tests for the writers and the model
    builder next to the unit tests, which is what a Mage-OS review asks for.
2. **Worker parity and memory.** The parity gate against the worker endpoint with the
   headers, stores, currencies, customer groups and filters varied per query, so a memo
   that keeps what it must not shows up as a diff, and the worker's memory per process
   sampled over a long run, so a memo that grows per request shows up as a slope.
3. **A leaner configurable document.** JSON key names are a third of a listing page and
   `configurableOptions` another third: per value the uid, the attribute id, the swatch
   thumbnail and `use_default_value` are derivable from the value index, the option and the
   swatch file. Stored compact and expanded by the resolver, a 200-item page drops from
   1.8 MB to about 1.2 MB and its multi-search from 22 to about 15 ms. Needs a products
   re-export.
4. **Extension hooks in the Api module.** A query-time filter on the listing, a ranking
    hook on the search request and a permission hook on the documents, so an extension
    plugs in without a preference on a class of this package.
5. **Fixed product taxes.** The last price display setup that falls back to core.
    The weee amounts per product travel on the document; the read side adds them the
    way the weee adjustment does.
6. **Package publishing.** A subtree split of the module directories to their own
    repositories and a release on packagist, so the modules install separately while
    the repository stays one.

7. **Cart, wishlist and order products.** A cart item's product still loads from the
   database. A plugin on the cart items data swaps in the document model by product
   id; the quote keeps what it owns, the row price, the options and the quantity checks.
8. **Luma frontend integration.** `*Frontend` modules next to the `*GraphQl` ones: the
   product listing collection, the product page and the layered navigation read from the
   base modules. The price rendering goes through the pricing system, so it needs its
   own document-backed price providers.
9. **Hyvä frontend integration.** The same base as Luma with Hyvä's view models.
10. **Parity on every surface.** The gate compares GraphQL responses. A Luma or Hyvä
   listing needs a gate of its own: the rendered listing and product page on both
   paths, so an integrator proves an extension on every surface it touches.
11. **REST integration.** The product repository and the search API behind the same
   document models, for headless setups that read the catalog over REST.
