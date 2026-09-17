# GraphCommerce_CatalogStorefrontSearch

What this package changes about core's catalog search, each behind a setting under Stores >
Configuration > Catalog > Catalog > Catalog Storefront Search. It plugs into
Magento_Elasticsearch and Magento_Search and reads no document, so it runs on its own.

| Setting | Default | Effect |
| --- | --- | --- |
| `catalog/storefront_search/entity_id_sort` | 1 | [`Plugin/EntityIdField`](Plugin/EntityIdField.php) writes the product id as an integer field of the fulltext document and `EntityIdSort` sorts the listing tie-break on it, where core runs a painless script over every matching document: 41 ms against 6 ms on an unfiltered listing over 308 000 visible products. The data patch invalidates the fulltext indexer, and a document indexed before the reindex sorts last among its ties. |
| `catalog/storefront_search/record_search_terms` | 0 | [`Plugin/SearchTermRecording`](Plugin/SearchTermRecording.php) keeps the search term write off the request. The search terms report and the search suggestions keep their current state. |
| `catalog/storefront_search/result_window` | 0 | [`Plugin/ResultWindowSetting`](Plugin/ResultWindowSetting.php) puts `max_result_window` on a new product search index, `ResultWindowPageSize` gives core's adapter the same number, and the backend model puts it on the indices that exist. Beyond the window core walks the result in windows of 10 000 hits with the aggregations in every window: page 2000 of an unfiltered listing over 300 000 products takes 32 windows and 2.4 seconds, against 0.2 seconds as one query. A deep page holds a sorted heap of that many hits in the memory of the engine. |

[`Plugin/FieldNameMemo`](Plugin/FieldNameMemo.php) answers one search index field name per
attribute code and context per request, where the core mapper asks several times.
