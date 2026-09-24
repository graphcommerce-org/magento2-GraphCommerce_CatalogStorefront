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
  per request.

A category without a document loads from the database.
