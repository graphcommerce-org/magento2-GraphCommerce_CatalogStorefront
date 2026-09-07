# GraphCommerce_CatalogStorefrontWorker

What a FrankenPHP worker keeps between requests, each memo under a generation that a cache flush or a config cache clean lifts.

- The parsed and validated query documents and the built schema per query shape.
- The deployment config check, the guest tax factors and rates, the customer group and the currency rates. Nothing is keyed by customer.
- The reload of the system config, the stores and the search request config after a response runs once per config generation; between generations only the sessions close, and the EAV attributes stay as core's own reset keeps them.
