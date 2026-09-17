# GraphCommerce Catalog Storefront

Magento keeps the write side: the Admin, the catalog tables and the indexers stay as they
are. The catalog reads come from one document per product and store view in OpenSearch:
the catalog GraphQL queries, and, per store view, the rendered category, search and
product pages of a Luma or Hyvä theme. A field the documents do not carry is answered by
the core resolver of that field, so the storefront code and the GraphQL schema stay the
same.

[`magento/commerce-data-export`](https://github.com/magento/commerce-data-export) is
Adobe's exporter: it turns the catalog into feed rows for Adobe's SaaS services, as
ordinary Magento indexers. This package implements the exporter's delivery seam and
writes those rows into the documents ([docs/feed-writer.md](docs/feed-writer.md)).

## Install and enable

The shop runs Mage-OS or Magento Open Source 2.4.7 or later on PHP 8.1 to 8.4, with
OpenSearch as the search engine of Stores > Configuration > Catalog > Catalog Search. The
documents use that same connection through core's OpenSearch client.
`magento/commerce-data-export` 103.4 or later comes from repo.magento.com, or from the
Mage-OS fork [mage-os/mageos-commerce-data-export](https://github.com/mage-os/mageos-commerce-data-export),
which an install without Adobe keys adds as composer `path` repositories, as
[.github/workflows/ci.yml](.github/workflows/ci.yml) does.

1. Install the package and compile. Composer autoload registers every module, and
   `setup:upgrade` enables them.

   ```sh
   composer require graphcommerce/magento-catalog-storefront
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   ```

2. Reindex the catalog search. `GraphCommerce_CatalogStorefrontSearch` writes the product
   id into the fulltext document and sorts every listing's tie-break on it; the install
   invalidates the fulltext indexer for that field.

   ```sh
   bin/magento indexer:reindex catalogsearch_fulltext
   ```

3. Export the two scopes feeds. Every other writer reads the store views with their
   media base URL, the website ids and the customer groups from their documents, and
   refuses a batch whose scope documents are missing.

   ```sh
   bin/magento indexer:reindex scopes_website_data_exporter scopes_customergroup_data_exporter
   ```

4. Build the documents. `catalog-storefront:rebuild` stages a fresh index per entity and
   store view, truncates the feed tables so the exporter sends every row, runs the feed
   indexers and promotes the indices when they are through.

   ```sh
   bin/magento catalog-storefront:rebuild
   ```

5. Keep the documents up to date. The feed indexers follow the catalog through their mview
   changelogs, so cron exports each change.

   ```sh
   bin/magento indexer:set-mode schedule \
     catalog_data_exporter_products catalog_data_exporter_product_prices \
     catalog_data_exporter_product_variants catalog_data_exporter_categories \
     catalog_data_exporter_product_attributes inventory_data_exporter_stock_status \
     catalog_data_exporter_product_reviews catalog_data_exporter_rating_metadata \
     scopes_website_data_exporter scopes_customergroup_data_exporter
   ```

6. Serve the catalog GraphQL of a store view from the documents.

   ```sh
   bin/magento config:set --scope=stores --scope-code=default catalog/storefront_documents/serve_graphql 1
   bin/magento cache:flush
   ```

7. Generate the storefront key. It unlocks the path header and the fallback report for a
   request that sends it.

   ```sh
   bin/magento config:set catalog/storefront_documents/key ""
   bin/magento config:show catalog/storefront_documents/key
   ```

8. Check the result. `catalog-storefront:status` prints the documents per store view
   against the products of the website, and per feed its rows, the rows a retry is pending
   for, the last export and the indexer state. It fails on waiting rows and on an invalid
   indexer, so a deployment check can call it.

   ```sh
   bin/magento catalog-storefront:status
   ```

9. Read one request's own answer. The key plus `X-Catalog-Storefront: documents` picks the
   path for that request alone, and `extensions.catalogStorefront` carries the path and
   every fallback to core with its reason.

   ```sh
   curl -s https://shop.example/graphql \
     -H 'Content-Type: application/json' \
     -H 'X-Catalog-Storefront-Key: <key>' \
     -H 'X-Catalog-Storefront: documents' \
     -d '{"query":"{products(search:\"bag\",pageSize:24){items{sku name price_range{minimum_price{final_price{value}}}}}}"}'
   ```

10. Compare both paths over the whole query set.
    [`catalog-storefront:parity`](src/CatalogStorefrontGraphQl/Console/Command/Parity.php)
    runs every query of [dev/parity/queries](dev/parity/queries) on the core path and on
    the document path and diffs the responses.

    ```sh
    bin/magento catalog-storefront:parity https://shop.example/graphql
    ```

## Why a read model

### What core pays for, per request

Core builds a product list from the catalog tables at the moment the visitor asks for it.
Each listed product costs a row in `catalog_product_entity_<backend type>` per attribute
the page selects, a `catalog_product_index_price` row per customer group and website, a
stock join,
and, for a configurable, grouped or bundle product, the load of its children with their
own attributes and prices. The cost follows the catalog shape: the large profile below
holds 1 095 attributes per store view, 5 websites, 4 customer groups and 192 000 variants
under 8 000 configurable products.

Measured with the GraphCommerce product list query
([dev/parity/queries/13-graphcommerce-product-list.graphql](dev/parity/queries/13-graphcommerce-product-list.graphql)),
guest, prices excluding tax, median over the wire, no page cache, on the large profile of
`setup:performance:generate-fixtures` (502 050 products in 5 websites, 3 000 categories,
20 catalog price rules):

| Listing | php-fpm, core | php-fpm, documents | worker, core | worker, documents |
| --- | --- | --- | --- | --- |
| 24 items of a 12 463-product category | 238 ms | 93 ms | 133 ms | 41 ms |
| 200 items of that category | 1 433 ms | 145 ms | 624 ms | 98 ms |
| 200 items of a search over 308 000 visible products | 1 549 ms | 210 ms | 519 ms | 148 ms |
| 200 items unfiltered | 1 661 ms | 194 ms | 629 ms | 130 ms |

### What a cache does with that cost

The cost stays on the request. A cache holds the answer of a request that already paid it,
and the next miss pays it again. The misses are the normal traffic of a catalog:

- A feed write purges the cache tags of the products and categories in its batch
  (`identities` on [`Model/Document/Delivery`](src/CatalogStorefront/Model/Document/Delivery.php)),
  so every price change, stock change and product save empties the entries of those
  products. A catalog reindex and a cache flush empty all of them.
- The long tail of URLs: a category page 7, a sort order, a page size and a filter
  combination are each a cache entry of their own, and layered navigation multiplies them.
- Customer group prices: core's GraphQL response cache id carries the customer group and
  the customer's tax rate
  (`Magento\CustomerGraphQl\CacheIdFactorProviders\CustomerGroupProvider` and
  `CustomerTaxRateProvider`), so each group and each tax destination fills its own slot.

### What this package does

The cost is paid one time at the feed side, when the data changes. The exporter builds the
row of every changed entity, hashes it and drops the row whose hash equals its last
export; the rest reaches the writers ([docs/feed-writer.md](docs/feed-writer.md)). On the
large profile the document store takes 0.41 ms per products row, 0.21 ms per prices row,
0.75 ms per stock row and 0.86 ms per variants row: 15 minutes of the 64 minutes the
2.5 million products rows take, the rest being the exporter's own queries and hashing.

A request is then a search plus a document read. A listing page costs two OpenSearch round
trips: core's product search, and one multi-search that takes the documents of the page
with the price aggregations of their composites. The number of products in the catalog
does not enter that request.

### The arguments against it

**"Varnish covers it."** A miss pays the core path: 1 433 ms against 145 ms for a 200-item
category listing on php-fpm. Every feed write empties the entries of the products it
touched, which is what keeps the prices true.

**"MySQL and indexer tuning is enough."** The numbers above are measured with every core
indexer complete: stock, price, category products, EAV and fulltext. A 24-item category
listing still costs 238 ms on php-fpm, because the request itself joins the attribute and
price rows of its 24 products. Tuning pays where the work is the search engine's:
`GraphCommerce_CatalogStorefrontSearch` sorts the listing tie-break on a field instead of
a painless script over every match (41 ms to 6 ms on an unfiltered listing over 308 000
visible products) and puts a result window over the catalog on the index (page 2000 of
that listing: 2.4 seconds in 32 windows against 0.2 seconds in one query). That module
needs no documents. The 238 ms of the 24-item listing stay on the request: they are PHP
time and database time, in core's own resolvers.

**"Our catalog is small."** The cost per request follows the attributes, store views,
customer groups and children of a product, not the number of products. On the demo catalog
the same 200-item query takes 816 ms on the core path against 112 ms on the document path
(php-fpm), and 425 ms against 78 ms on a FrankenPHP worker. Customer groups are the
clearest example: with a catalog rule on all groups, 4 groups against 54 take the prices
feed from 4 694 to 15 794 rows, a rule product's document from 6 KB to 23 KB, and the
200-item listing on the worker from 78 ms to 86 ms, because a product's rows of every
group arrive in one batch and the document keeps one nested entry per group.

**"A read model gets out of sync."** Three mechanisms answer for it. The exporter compares
the hash of every row with its last export, so a full reindex re-sends what changed and
writes nothing for the rest. A read the documents cannot answer falls back to the core
resolver, and a request with the storefront key names every fallback with its reason.
`catalog-storefront:parity` diffs both paths query by query, and `--soak` repeats the
accepted queries with the customer, the page size, the sku list and the path order varied
per request: 3 000 requests, 0 differences, memory slope 250.7 KB per 100 requests against
a 1 500 KB limit
([docs/validation/2026-09-15-worker-soak-local.json](docs/validation/2026-09-15-worker-soak-local.json)).
`catalog-storefront:status` fails while a feed row waits or a feed indexer is invalid.

## What is served from documents

The path header and the setting decide per request. Every plugin below gates on
[`Model/Mode`](src/CatalogStorefront/Model/Mode.php) first, so `X-Catalog-Storefront: core`
runs core alone.

| Read path | Module | Falls back to core when |
| --- | --- | --- |
| `products` search, filter and sort | `...GraphQl` | a listed product has no document |
| `categories`, `categoryList`, a product's `categories` | `...GraphQl` | the filter is one the category documents do not answer, or the page is past the last |
| `aggregations`, layered navigation labels, price step | `...GraphQl`, base | the store view has no attribute documents, or the filtered categories have no document |
| `custom_attributesV2`, `attributesList` | `...GraphQl` | the filter names a property the attribute documents do not carry |
| `media_gallery`, `url_rewrites` | `...GraphQl` | the document carries none |
| `related_products`, `upsell_products`, `crosssell_products` | `...GraphQl` | the product of the query has no document |
| `price_range`, `price`, `price_tiers`, `tier_prices`, `fixed_product_taxes` | `...PriceGraphQl` | fixed product taxes are active on a composite product |
| `stock_status`, `only_x_left_in_stock`, `quantity`, `min_sale_qty`, `max_sale_qty` | `...InventoryGraphQl` | the product has no document |
| `configurable_options`, `variants`, `configurable_product_options_selection` | `...ConfigurableProductGraphQl` | the configurable document carries no `configurableOptions` |
| `items` and `price_details` of a bundle | `...BundleProductGraphQl` | the product has no document |
| `items` of a grouped product | `...GroupedProductGraphQl` | the product has no document |
| `downloadable_product_links`, `downloadable_product_samples` | `...DownloadableGraphQl` | a sample carries no `sample_id` |
| `rating_summary`, `review_count`, `reviews` | `...ReviewGraphQl` | the page is past the last |
| The products of cart items, wish list items and order items | `...QuoteGraphQl`, `...WishlistGraphQl`, `...SalesGraphQl` | an item's product has no document |
| Rendered category and search listing pages, product detail pages | `...ProductFrontend` | one product of the page has no document, and the page then loads from the database with the ids logged |
| A configurable's children, super attributes and lowest price on a rendered page | `...ConfigurableProductFrontend` | the product has no document |

## Reference

### Commands

| Command | Does |
| --- | --- |
| `catalog-storefront:rebuild [product\|category\|attribute\|review\|rating ...]` | Stages a fresh index per store view, truncates the feed tables of the entity, runs its feed indexers, promotes the indices. `--promote` promotes what a feed run outside the command filled. |
| `catalog-storefront:status` | Documents per store view and per feed the rows, the waiting rows, the last export and the indexer state. Fails on waiting rows or an invalid indexer. |
| `catalog-storefront:parity <endpoint>` | Both paths over `dev/parity/queries`, diffed. `--queries`, `--header`, `--dump`, `--report`, `--attempts`, `--warm`, `--candidate-endpoint`, `--insecure`, and the soak options `--soak`, `--token`, `--token-file`, `--probe-header`, `--probe-share`, `--memory-sample`, `--memory-slope`, `--worker-container`, `--worker-process`. |
| `catalog-storefront:parity:listing <base url>` | Renders `dev/parity/listing-pages.txt` on both paths and compares the lines. `--pages`, `--dump`, `--warm`, `--host`, `--header`. |
| `catalog-storefront:parity:detail <base url>` | The same for `dev/parity/detail-pages.txt`. `--marker` names the text a real product page carries, so an error page is not judged. |

### Settings

Stores > Configuration > Catalog > Catalog > Catalog Storefront Document Store:

| Path | Default | Effect |
| --- | --- | --- |
| `catalog/storefront_documents/index_enabled` | 1 | The writers of these modules store each feed batch. Another package that consumes the same feeds has its own flag. |
| `catalog/storefront_documents/serve_graphql` | 0, per store view | The catalog GraphQL reads come from documents. |
| `catalog/storefront_documents/serve_plp` | 0, per store view | Rendered category and search listing pages build their products from documents. |
| `catalog/storefront_documents/serve_pdp` | 0, per store view | A rendered product detail page takes its product from a document. |
| `catalog/storefront_documents/key` | Generated on a save with the field empty | The value of the `X-Catalog-Storefront-Key` header that unlocks the path header and the fallback report. |
| `catalog/storefront_documents/index_prefix` | `catalog_storefront` | The first part of every index name. |

Stores > Configuration > Catalog > Catalog > Catalog Storefront Search, from
`GraphCommerce_CatalogStorefrontSearch`, which runs without the document store:

| Path | Default | Effect |
| --- | --- | --- |
| `catalog/storefront_search/entity_id_sort` | 1 | The listing tie-break sorts on the product id field of the fulltext document. Needs a fulltext reindex, which the install schedules. |
| `catalog/storefront_search/record_search_terms` | 0 | Magento writes the search term of every search. Off, the search terms report and the suggestions keep their current state. |
| `catalog/storefront_search/result_window` | 0 | The hits the engine serves with one query. 0 leaves the engine's own limit of 10 000. A save puts the window on the product search index of every store view. |

Stores > Configuration > Catalog > Catalog > Layered Navigation > Price Navigation Step
Calculation takes a fourth value, Single range: the `price` aggregation is one option from
the lowest to the highest price of the result, which is what a price slider reads. Core's
other modes run two or three more search queries per listing for the intervals.

### Headers

| Header | Value | Effect |
| --- | --- | --- |
| `X-Catalog-Storefront-Key` | The configured key | The request may pick its path and gets the fallback report. |
| `X-Catalog-Storefront` | `documents` or `core` | The path of this request. It is a factor of the response cache id, of the resolver result cache keys and of the page cache id, and a keyed GraphQL request is never cacheable. |

### Indices

Each entity and store view has one index behind a read alias `<prefix>_<entity>_<store view
code>` and a write alias `<prefix>_<entity>_<store view code>_write`, for example
`catalog_storefront_product_default`. The entities are `product`, `category`, `attribute`,
`review` and `rating`. The two scopes feeds write `<prefix>_website_global` and
`<prefix>_customer_group_global`. Every mapping is `dynamic: false` and maps only the
fields a request filters, sorts or aggregates on (`EntityMappings` in
[src/CatalogStorefront/etc/di.xml](src/CatalogStorefront/etc/di.xml)); every other field
stays in `_source`. A mapping change reaches an index through `setup:di:compile` and then
`catalog-storefront:rebuild <entity>`.

## Extend

### Add a field to the product document

Declare the field on the exporter's record with a provider, in the `etc/et_schema.xml` of
your module. The provider takes the keys the `using` nodes name and answers one row per
entity and store view, under the key the exporter joins on.
[`Provider/TaxClass`](src/CatalogStorefront/Model/DataExporter/Provider/TaxClass.php) is
the same shape against the EAV tables.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_DataExporter:etc/et_schema.xsd">
    <record name="Product">
        <field name="rentalDays" type="Int" provider="Vendor\Rental\Model\DataExporter\Provider\RentalDays">
            <using field="productId"/>
            <using field="storeViewCode"/>
        </field>
    </record>
</config>
```

```php
namespace Vendor\Rental\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

class RentalDays
{
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function get(array $values): array
    {
        $ids = array_unique(array_map(static fn(array $value) => (int)$value['productId'], $values));
        $connection = $this->resourceConnection->getConnection();
        $days = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('vendor_rental_product'), ['product_id', 'days'])
                ->where('product_id IN (?)', $ids)
        );
        $output = [];
        foreach ($values as $value) {
            $id = (int)$value['productId'];
            if (!isset($days[$id])) {
                continue;
            }
            $output[$value['storeViewCode'] . '_' . $id] = [
                'productId' => $value['productId'],
                'storeViewCode' => $value['storeViewCode'],
                'rentalDays' => (int)$days[$id],
            ];
        }

        return $output;
    }
}
```

The products writer stores the whole feed row, so `rentalDays` is on the document after
`bin/magento setup:di:compile` and `bin/magento catalog-storefront:rebuild product`. Serve
it with a prefiller, which fills the field on the product value the executor hands to the
child fields:

```php
namespace Vendor\Rental\Model\Prefill;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;

class RentalDays implements PrefillerInterface
{
    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('rental_days')) {
            return [];
        }

        return array_map(
            static fn(array $document) => ['rental_days' => $document['rentalDays'] ?? null],
            $documents
        );
    }
}
```

```xml
<type name="GraphCommerce\CatalogStorefrontGraphQl\Model\DocumentHydration">
    <arguments>
        <argument name="prefillers" xsi:type="array">
            <item name="rental" xsi:type="object">Vendor\Rental\Model\Prefill\RentalDays</item>
        </argument>
    </arguments>
</type>
<type name="GraphCommerce\CatalogStorefrontGraphQl\Plugin\Query\RoutePrefilledFields">
    <arguments>
        <argument name="prefilledFields" xsi:type="array">
            <item name="ProductInterface" xsi:type="array">
                <item name="rental_days" xsi:type="string">rental_days</item>
            </item>
        </argument>
    </arguments>
</type>
```

A field the prefiller leaves empty routes to the core resolver, so a product without a
document keeps core's answer.

### Add a writer

A writer takes the rows of one feed and stores them. It reads its rows and its own document
store, and nothing else. Register it under the feed name, next to the writers that are
there: the delivery calls every writer of the feed with the batch.
[`Writer/Stock`](src/CatalogStorefrontInventory/Model/Document/Writer/Stock.php) is a
complete example of a slice writer, and
[src/CatalogStorefrontInventory/etc/di.xml](src/CatalogStorefrontInventory/etc/di.xml) of
its registration:

```xml
<type name="GraphCommerce\CatalogStorefront\Model\Document\Delivery">
    <arguments>
        <argument name="writers" xsi:type="array">
            <item name="products" xsi:type="array">
                <item name="rental" xsi:type="object">Vendor\Rental\Model\Document\Writer\Rental</item>
            </item>
        </argument>
        <argument name="flags" xsi:type="array">
            <item name="rental" xsi:type="string">vendor_rental/documents/index_enabled</item>
        </argument>
        <argument name="identities" xsi:type="array">
            <item name="products" xsi:type="array">
                <item name="cat_p" xsi:type="array">
                    <item name="productId" xsi:type="string">productId</item>
                </item>
            </item>
        </argument>
    </arguments>
</type>
```

The writer name is a family: `flags` gives the family a configuration path, and the
delivery skips it while that path is off. `identities` names the cache tags and the row
keys the batch purges after the write.

[docs/feed-writer.md](docs/feed-writer.md) holds the full path from a MySQL write to a
document: the feeds, their providers, the change tracking and every writer with its index.

### Other extension points

| Argument | On | Adds |
| --- | --- | --- |
| `feeds` | [`Model/Feeds`](src/CatalogStorefront/Model/Feeds.php) | The feed indexers of an entity, for the rebuild and the status command. The rebuild runs the entities in this order. |
| `ranges` | [`Model/Read/PriceRanges`](src/CatalogStorefrontPrice/Model/Read/PriceRanges.php) | A `PriceRangeInterface` per product type id. |
| `mappings` | `EntityMappings` | The fields of an entity a request filters, sorts or aggregates on. |
| `judges` | The parity command | A `JudgeInterface` verdict per query, with both responses in hand. |

Another search engine implements
[`ProductDocumentStorageInterface`](src/CatalogStorefrontApi/Storage/ProductDocumentStorageInterface.php)
and
[`MetadataDocumentStorageInterface`](src/CatalogStorefrontApi/Storage/MetadataDocumentStorageInterface.php)
and sets the preferences in its own di.xml, as
[`GraphCommerce_CatalogStorefrontOpenSearch`](src/CatalogStorefrontOpenSearch) does.

[docs/skills/catalog-storefront-compatibility/SKILL.md](docs/skills/catalog-storefront-compatibility/SKILL.md)
is the guide for a module that adds catalog data: what to build at index time and at
request time, and how to prove it with the parity gate. An LLM loads it as a skill.

## Modules

Each directory under `src/` is a Magento module and a composer package
(`graphcommerce/module-catalog-storefront-<name>`) that depends on the core modules it
plugs into; the root package replaces them all. Enable the modules the shop's product
types and features need, next to the two Api modules, OpenSearch, the two Price modules,
the base module and the GraphQl module.

| Module | Adds |
| --- | --- |
| [`...Api`](src/CatalogStorefrontApi) | The contracts: document stores, feed writers, document fields, product documents, price ranges |
| [`...GraphQlApi`](src/CatalogStorefrontGraphQlApi) | The GraphQL contracts: prefillers, hydration, parity judges |
| [`...OpenSearch`](src/CatalogStorefrontOpenSearch) | The document stores on OpenSearch, through core's client |
| [`GraphCommerce_CatalogStorefront`](src/CatalogStorefront) | Feed delivery with the cache purge, the writers of the products, prices, categories, attributes and scopes feeds, documents to models, the fallback report, the storefront key, the rebuild and status commands |
| [`...GraphQl`](src/CatalogStorefrontGraphQl) | Listings, layered navigation, categories, media, URL rewrites, custom attributes, linked products, the request path, the parity command |
| [`...Price`](src/CatalogStorefrontPrice) / [`...PriceGraphQl`](src/CatalogStorefrontPriceGraphQl) | Display currency and tax at read time through core's tax service / the prices prefiller and the customer's tax address |
| [`...Inventory`](src/CatalogStorefrontInventory) / [`...InventoryGraphQl`](src/CatalogStorefrontInventoryGraphQl) | The stock slice / the stock fields |
| [`...ConfigurableProduct`](src/CatalogStorefrontConfigurableProduct) / [`...GraphQl`](src/CatalogStorefrontConfigurableProductGraphQl) / [`...Frontend`](src/CatalogStorefrontConfigurableProductFrontend) | Variants and the configurable range / options, variants, selection / a configurable on a rendered page |
| [`...BundleProduct`](src/CatalogStorefrontBundleProduct) / [`...GraphQl`](src/CatalogStorefrontBundleProductGraphQl) | Bundle feed fields and the bundle range / bundle items and price details |
| [`...GroupedProduct`](src/CatalogStorefrontGroupedProduct) / [`...GraphQl`](src/CatalogStorefrontGroupedProductGraphQl) | The grouped range / grouped items |
| [`...Downloadable`](src/CatalogStorefrontDownloadable) / [`...GraphQl`](src/CatalogStorefrontDownloadableGraphQl) | The downloadable range / links and samples |
| [`...Review`](src/CatalogStorefrontReview) / [`...GraphQl`](src/CatalogStorefrontReviewGraphQl) / [`...Frontend`](src/CatalogStorefrontReviewFrontend) | Review and rating feeds / rating summary, breakdown and reviews / the rating collection of the rendered review form |
| [`...QuoteGraphQl`](src/CatalogStorefrontQuoteGraphQl) | The display fields of a cart item's product |
| [`...WishlistGraphQl`](src/CatalogStorefrontWishlistGraphQl) | Wish list item products, one document request per list |
| [`...SalesGraphQl`](src/CatalogStorefrontSalesGraphQl) | Order item products, one document request per order |
| [`...ProductFrontend`](src/CatalogStorefrontProductFrontend) | Rendered listing and product detail pages from documents, and their two parity commands |
| [`...Search`](src/CatalogStorefrontSearch) | Cheaper core fulltext listings, each behind a setting. Runs without the document store |
| [`...Worker`](src/CatalogStorefrontWorker) | What a FrankenPHP worker keeps between requests. Costs a cache read per request under php-fpm |
| [`...Elasticsuite`](src/CatalogStorefrontElasticsuite) | The document store on the cluster Smile ElasticSuite is configured with |
| [`...Explorer`](src/CatalogStorefrontExplorer) | The path switcher in the MageOS_GraphQLAdminHtml API explorer |
| [`...Adminhtml`](src/CatalogStorefrontAdminhtml) | Catalog > Catalog Storefront: the store views, customer group price keys and feed state, read-only |
| [`...Profiler`](src/CatalogStorefrontProfiler) | A MageOS_Profiler span per document store request |

## Tests

`phpunit.xml.dist` runs the unit tests of every module against the Magento installation
that holds the package (`MAGENTO_ROOT`, else the project two levels up). The writer tests
under `Test/Unit/Model/Document` hold the document contract: a feed batch in, the documents
out. [.github/workflows/ci.yml](.github/workflows/ci.yml) runs them on the latest Mage-OS
release, installs the sample data with OpenSearch, MySQL and Valkey, adds the fixtures of
the query set, exports the feeds and runs the parity gate in five price setups, each as a
guest, as a signed-in customer and on a second website.
[dev/parity/freshness.php](dev/parity/freshness.php) then changes a price, a fixed product
tax and a stock status and reads the documents back, so a table or a setting the feeds do
not watch shows up.
