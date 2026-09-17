# GraphCommerce_CatalogStorefrontApi

The contracts every other module and every frontend codes against. It has no dependency
other than Magento_Framework and the exporter's `Magento_DataExporter`.

- [`Storage\ProductDocumentStorageInterface`](Storage/ProductDocumentStorageInterface.php)
  and [`Storage\MetadataDocumentStorageInterface`](Storage/MetadataDocumentStorageInterface.php):
  the document stores. A search engine module implements both and sets the preferences.
- [`Storage\EntityMappings`](Storage/EntityMappings.php): the fields of an entity a request
  filters, sorts or aggregates on, which is what the index maps.
- [`Document\FeedWriterInterface`](Document/FeedWriterInterface.php): writes the rows of one
  feed.
- [`Read\ProductDocumentsInterface`](Read/ProductDocumentsInterface.php),
  [`Read\DocumentContext`](Read/DocumentContext.php),
  [`Read\PriceRangeInterface`](Read/PriceRangeInterface.php) and
  [`Read\Amount`](Read/Amount.php): documents and models by id, the store, group and price
  data one read answers for, and the range of one product type.
- `Registry\` and `Service\`: the catalog resource configuration the Admin module reads.
  The base module binds them to a read-only view of the Magento store views, customer
  groups and feed state.
