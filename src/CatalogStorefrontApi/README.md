# GraphCommerce_CatalogStorefrontApi

Besides the document storage and read contracts, `Search/ProductProjectorInterface`
is the boundary from an assembled product document to a separate search
projection. Implementations receive the store, store-view and website context.
They consume the resolved document slices; in particular, they must not
recalculate customer-group price fallback from raw feed rows.

The contracts every module and every frontend codes against.

- `Storage\ProductDocumentStorageInterface`, `Storage\MetadataDocumentStorageInterface`: the document stores.
- `Document\FeedWriterInterface`: writes one commerce-data-export feed into the documents.
- `Read\ProductDocumentsInterface`, `Read\DocumentContext`, `Read\PriceRangeInterface`: documents to models, and the price range of one product type.
