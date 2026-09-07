# GraphCommerce_CatalogStorefrontOpenSearch

The document stores on OpenSearch.

- One index per entity and store view behind a read alias and a write alias; a rebuild stages a fresh index and promotes it when the feeds are done.
- The connection is core's search engine client, so the documents share the cluster of the catalog search.
- Only the fields a request filters, sorts or aggregates on are mapped; every other field stays in the source.
- A read of more ids than the engine's result window is one multi-search with one search per window.
