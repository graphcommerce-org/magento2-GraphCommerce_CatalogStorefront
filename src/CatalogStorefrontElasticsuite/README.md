# GraphCommerce_CatalogStorefrontElasticsuite

The document store on the cluster Smile ElasticSuite is configured with. Install it only if you run
ElasticSuite.

ElasticSuite keeps its connection under its own settings, so with `catalog/search/engine` set to
`elasticsuite` the document store finds nothing and falls back to an unauthenticated
`localhost:9200` — quietly. This registers that engine with core's client resolver and answers it
from ElasticSuite's settings instead.

- Agnostic for free: ElasticSuite and core's client both use `opensearch-project/opensearch-php`, so
  this reaches whatever ElasticSuite reaches. Nothing detects an engine.
- No ElasticSuite class is named, only config paths, so it compiles without ElasticSuite and is
  simply never selected there.
- Read from `smile_elasticsuite_core_base_settings/es_client`: `servers` first entry → `hostname`
  and `port`, `enable_https_mode` → the scheme, `enable_http_auth` with both credentials → auth,
  `timeout`. An empty server list raises rather than inventing a default.
- Two limits: core's client takes a single host, so a multi-node ElasticSuite keeps failover for
  search but not for documents; and it has no `enable_certificate_validation`, so a self-signed
  cluster ElasticSuite reaches will fail here. Both are fixed by a `SearchClient` subclass built
  from ElasticSuite's full option set — not written, not yet needed.
