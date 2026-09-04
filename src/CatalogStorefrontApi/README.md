# GraphCommerce_CatalogStorefrontApi

The contracts every module and every frontend codes against.

- `Storage\ProductDocumentStorageInterface`, `Storage\MetadataDocumentStorageInterface`: the document stores.
- `Document\FeedWriterInterface`: writes one commerce-data-export feed into the documents.
- `Document\ProductDocumentFieldInterface`: a product document field computed at index time.
- `Read\ProductDocumentsInterface`, `Read\DocumentContext`, `Read\PriceRangeInterface`: documents to models, and the price range of one product type.
