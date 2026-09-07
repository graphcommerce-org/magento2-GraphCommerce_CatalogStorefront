# GraphCommerce_CatalogStorefrontElasticsuite

The document store on the cluster Smile ElasticSuite is configured with. Install it only with ElasticSuite.

- Registers the `elasticsuite` engine with core's client resolver, on core's own client factory, with the host, port, scheme, credentials and timeout from ElasticSuite's settings. Without it the document store on that engine falls back to an unauthenticated `localhost:9200`.
- Names no ElasticSuite class, only config paths, so it compiles without ElasticSuite and is never selected there.
- Core's client takes one host and has no certificate validation flag, so a multi-node or self-signed cluster keeps those for search only.
