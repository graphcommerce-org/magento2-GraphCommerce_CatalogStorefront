# GraphCommerce_CatalogStorefrontElasticsuite

The document store on the cluster Smile ElasticSuite is configured with. Install this module
with ElasticSuite only.

- [etc/di.xml](etc/di.xml) registers the `elasticsuite` engine with core's client resolver,
  on core's own client factory. [`Model/Client/Options`](Model/Client/Options.php) answers
  the host, port, scheme, credentials and timeout from
  `smile_elasticsuite_core_base_settings/es_client`. Without this module the document store
  on that engine reaches an unauthenticated `localhost:9200`.
- The module names config paths only, so it compiles without ElasticSuite, and core selects
  the engine only where ElasticSuite is installed.
- Core's client takes one host and holds no certificate validation flag, so a multi-node or
  self-signed cluster keeps those for the search engine.
