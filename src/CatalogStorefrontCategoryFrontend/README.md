# GraphCommerce_CatalogStorefrontCategoryFrontend

The categories of a rendered Luma or Hyvä page from the category documents, under the
`catalog/storefront_documents/serve_plp` setting of GraphCommerce_CatalogStorefrontProductFrontend
and its `X-Catalog-Storefront` header. Frontend area only.

- [`Plugin/ResourceFromDocuments`](Plugin/ResourceFromDocuments.php) loads a category from its
  document, and answers its children, its parents and its design parent from theirs. A built
  category carries its request path, so `getUrl()` asks no rewrite.
- [`Plugin/HasChildrenFromDocuments`](Plugin/HasChildrenFromDocuments.php) answers
  `hasChildren()` from the documents of the levels below.
- [`Model/Read/CategoryDocuments`](Model/Read/CategoryDocuments.php) reads each document once
  per request. A category read alone brings its parents and
  its children in one more read, since the page asks for them one after the other (design, page
  layout, breadcrumbs).
- [`Plugin/PriceStepFromCategory`](Plugin/PriceStepFromCategory.php) answers the price step of the
  layered navigation from the document of the filtered category, before the base module's plugin
  reads that document again.
- [`Model/LoadedCollection`](Model/LoadedCollection.php) holds the built children. A caller that
  clears it and loads it again gets the same categories back: its select carries no filter.

A category without a document loads from the database.
