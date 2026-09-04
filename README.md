# GraphCommerce Catalog Storefront

A catalog read model for Magento 2 / Mage-OS, built on `magento/commerce-data-export`.

The feeds (products, prices, inventory, variants, reviews, categories, attributes)
are computed by the maintained exporter modules as ordinary Magento indexers. This
package implements the delivery seam (`ExportFeedInterface`) to assemble the feed
slices into one product document per store view in OpenSearch, and serves catalog
GraphQL reads from those documents. A request runs no SQL of its own; any miss falls
back to the core resolver.

Toggle with `graphcommerce/catalog_storefront/serve_reads`.

## Modules

One composer package registers one module per directory under `src/`:

| Module | Serves |
| --- | --- |
| `GraphCommerce_CatalogStorefrontApi` | The contracts: document stores, feed writers, document fields, prefillers, price ranges, hydration |
| `GraphCommerce_CatalogStorefrontOpenSearch` | The document stores on OpenSearch |
| `GraphCommerce_CatalogStorefront` | Feed delivery, listings and layered navigation, categories, media, URL rewrites, custom attributes, product links, prices |
| `GraphCommerce_CatalogStorefrontInventory` | Stock status, only_x_left_in_stock, quantity, min and max sale qty |
| `GraphCommerce_CatalogStorefrontConfigurableProduct` | Configurable options, variants, options selection, the configurable price range |
| `GraphCommerce_CatalogStorefrontBundleProduct` | Bundle items, price details, the bundle price range |
| `GraphCommerce_CatalogStorefrontGroupedProduct` | Grouped items, the grouped price range |
| `GraphCommerce_CatalogStorefrontDownloadable` | Downloadable links and samples |
| `GraphCommerce_CatalogStorefrontReview` | Reviews, rating summary and breakdown |

Each module depends only on the core modules it plugs into. Enable the ones the
shop's product types and features need, plus Api, OpenSearch and the core.

## Folders

Inside a module the folders name the stage of the pipeline:

- `Model/DataExporter/` and `Plugin/DataExporter/`: patch-ups of commerce-data-export.
  `Provider/` classes add fields to the exporter's existing records through `et_schema.xml`,
  `Processor/` classes make a legacy feed export like the modern ones, the plugins fix
  what an exporter provider leaves out. They run at index time and may use SQL.
- `Model/Document/`: the document store side. `Delivery` receives each feed batch and
  hands it to the `Writer/` of that feed; `Field/` classes compute product document
  fields the feed lacks.
- `Model/Read/`: the request side. The hydration builds product models from documents
  and runs the `Prefill/` classes; `Price/` holds the range calculators.
- `Plugin/Resolver/`, `Plugin/Layer/`, `Plugin/GraphQl/`: the request path plugins on
  core resolvers.

## Extending

A module registers its parts through di.xml:

- `writers` on `Model\Document\Delivery`: a `FeedWriterInterface` per feed name.
- `fields` on the products writer: a `ProductDocumentFieldInterface` per computed field.
- `prefillers` on `Model\Read\DocumentHydration`: a `PrefillerInterface` fills fields on
  the product value from the model and the document, so the executor returns them
  without a resolver call. List the fields under `prefilledFields` on `ReuseSchema`.
- `ranges` on the price prefiller: a `PriceRangeInterface` per product type id.
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
bin/magento module:enable GraphCommerce_CatalogStorefrontApi GraphCommerce_CatalogStorefrontOpenSearch \
  GraphCommerce_CatalogStorefront GraphCommerce_CatalogStorefrontInventory \
  GraphCommerce_CatalogStorefrontConfigurableProduct GraphCommerce_CatalogStorefrontBundleProduct \
  GraphCommerce_CatalogStorefrontGroupedProduct GraphCommerce_CatalogStorefrontDownloadable \
  GraphCommerce_CatalogStorefrontReview
bin/magento setup:upgrade --keep-generated
bin/magento setup:di:compile
```

Then add the `catalog-store-front` connection block to `app/etc/env.php` (see
`src/CatalogStorefrontOpenSearch/Model/Client/Config.php` for the keys), run the
commerce-data-export indexers, and turn on `serve_reads`. A composer install from the
package registers every module through its autoload instead of the links.

## Parity

`dev/parity/run.php <endpoint>` runs every query in `dev/parity/queries/` against the
database path and the document path, diffs the responses and, with the attribution
module (`dev/attribution/Module`, linked as `GraphCommerce_CatalogStorefrontAttribution`)
enabled in the worker, fails a document-path query that runs a SQL lookup. See
`CLAUDE.md` for the operating notes and the known deviations.
