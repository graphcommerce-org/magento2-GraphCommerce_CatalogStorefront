# GraphCommerce_CatalogStorefrontWorker

What a FrankenPHP worker keeps between requests, each memo under a generation that a cache flush or a config cache clean lifts.

- The parsed and validated query documents and the built schema per query shape.
- The deployment config check, store, group and website relation rows, guest tax factors and rates, the customer group and currency rates. Nothing is keyed by customer.
- Changed system config, stores and search request config reload before request registration, once per config generation; after the current headers are registered, the keyed and default document path and report decisions are evaluated again against that config. Between generations only the sessions close, and the EAV attributes stay as core's own reset keeps them.
