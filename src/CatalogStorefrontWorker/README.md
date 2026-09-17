# GraphCommerce_CatalogStorefrontWorker

What a FrankenPHP worker keeps between requests. Enable it on a worker deployment
(`mage-os/module-worker-mode`); under php-fpm each memo costs a cache read per request and
keeps nothing.

Every memo lives under a generation ([`Model/Generation`](Model/Generation.php): a token in
the cache with the config tag), so a cache flush or a config cache clean lifts all of them.
A save through the tax rule, rate or class repository, the group repository or the currency
rate resource bumps the tax or the currency generation.

- The parsed query documents per query text, the validated documents and one built schema
  per query shape. Core's parser drops its cache in the state reset, which cost 7 ms per
  request.
- The deployment config check, the store and website relation rows, the guest tax factor of
  the cache id, the guest tax rates, the customer group lookups, the currency rates and the
  theme of a full path.
- Nothing is keyed by customer: a signed-in request carries its own address and stays with
  core's per-request caches.
- [`Plugin/State/ReloadPerGeneration`](Plugin/State/ReloadPerGeneration.php) runs the reload
  processors (system config, stores, search request config) once per config generation.
  Between generations the request reload closes the sessions, and the EAV attribute objects
  stay as core's own reset keeps them.
