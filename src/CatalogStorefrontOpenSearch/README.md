# GraphCommerce_CatalogStorefrontOpenSearch

The document stores on OpenSearch. It depends on Magento_AdvancedSearch, Magento_OpenSearch
and `opensearch-project/opensearch-php`.

- [`Model/Client`](Model/Client.php) takes core's OpenSearch client from the engine
  resolver, so the documents live on the connection of Stores > Configuration > Catalog >
  Catalog Search. `updateLists` changes an id list inside a stored document with a painless
  script, so two writers keep each other's change.
- [`Model/Index`](Model/Index.php) keeps one index per entity and store view behind the
  read alias `<prefix>_<entity>_<store view code>` and the write alias with `_write`.
  `stage` puts a fresh index behind the write alias, `promote` moves the read alias to it
  and deletes the old one.
- The index maps the `EntityMappings` fields of its entity and nothing else
  (`dynamic: false`); every field stays in `_source`. A read of mapped fields alone takes
  them from the doc values without the source.
- A read by id is a search with an ids query. A read of more ids than the result window of
  the engine is one multi-search with a search per window.
- `catalog/storefront_documents/index_prefix` names the first part of every index.
- `catalog/storefront_documents/read_compression` makes reads travel gzipped, for a slow link
  to the engine. Plain by default: on a cluster network the engine's compression of 200
  documents costs more CPU time than the transfer saves.
- With `ext-simdjson_plus` installed, the client decodes the responses about twice as fast.

Another search engine implements the two storage interfaces of
GraphCommerce_CatalogStorefrontApi and sets the preferences in its own di.xml, as
[etc/di.xml](etc/di.xml) does here.
