# GraphCommerce_CatalogStorefrontWorker

The built objects a FrankenPHP worker keeps between requests. Enable it on a worker
deployment (`mage-os/module-worker-mode`); under php-fpm each memo costs a cache read per
request and keeps nothing.

The data a request reads per node, the tax rates, the customer groups, the currency rates,
the deployment config check, the store and website relation rows and the guest tax factor,
are FastBoot features (`graphcommerce/magento-fast-boot`): local files under the FastBoot
version, which a worker keeps in memory.

Every memo lives under a generation ([`Model/Generation`](Model/Generation.php): a token in
the cache with the config tag), so a cache flush or a config cache clean lifts all of them.

- The parsed query documents per query text, the validated documents and one built schema
  per query shape. Core's parser drops its cache in the state reset, which cost 7 ms per
  request.
- The theme of a full path.
- [`Plugin/State/ReloadPerGeneration`](Plugin/State/ReloadPerGeneration.php) runs the reload
  processors (system config, stores, search request config) once per config generation.
  Between generations the request reload closes the sessions, and the EAV attribute objects
  stay as core's own reset keeps them.
